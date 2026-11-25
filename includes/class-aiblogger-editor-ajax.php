<?php
defined('ABSPATH') || exit;

class AIBlogger_Editor_Ajax extends AIBlogger_Ajax_Controller {

    public static function init(): void {
        add_action('wp_ajax_aiblogger_editor_rewrite', [__CLASS__, 'editor_rewrite']);
        add_action('wp_ajax_aiblogger_editor_image', [__CLASS__, 'editor_image']);
    }

    public static function editor_rewrite(): void {
        try {
            self::check_nonce();
            if (!AIBlogger_Utils::can_generate()) {
                wp_send_json_error(['message' => 'Sin permisos.'], 403);
            }

            @set_time_limit(240);

            $opts = AIBlogger_Utils::get_options();
            if (empty($opts['api_key'])) {
                wp_send_json_error(['message' => 'Configura la API Key en Configuracion.'], 400);
            }

            $budget = self::enforce_budget_or_fail();

            $post_id = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
            if ($post_id > 0 && !current_user_can('edit_post', $post_id)) {
                wp_send_json_error(['message' => 'Sin permisos para editar este post.'], 403);
            }

            $text = isset($_POST['text']) ? wp_strip_all_tags((string)wp_unslash($_POST['text'])) : '';
            $instruction = isset($_POST['instruction']) ? sanitize_textarea_field((string)wp_unslash($_POST['instruction'])) : '';

            $text = trim($text);
            if ($text === '') {
                wp_send_json_error(['message' => 'No hay texto para reescribir.'], 400);
            }

            $model = (string)($opts['text_model'] ?? 'gemini-3-flash-preview');
            $client = new AIBlogger_Gemini_Client((string)$opts['api_key']);

            $prompt = "Reescribe el siguiente texto manteniendo el significado, mejorando claridad y estilo. "
                . "Entrega solo el texto final, sin explicaciones.\n\n"
                . ($instruction ? ("Instruccion adicional: " . $instruction . "\n\n") : '')
                . "Texto:\n" . $text;

            $res = $client->generate_text_with_usage($model, $prompt, ['temperature' => 0.4]);
            if (is_wp_error($res)) {
                wp_send_json_error(['message' => $res->get_error_message(), 'details' => $res->get_error_data()], 500);
            }

            $out = trim((string)($res['text'] ?? ''));
            if ($out === '') {
                wp_send_json_error(['message' => 'No se obtuvo texto reescrito.'], 500);
            }

            $usage_norm = AIBlogger_Utils::normalize_usage(is_array($res['usage'] ?? null) ? ($res['usage'] ?? []) : []);

            if (!empty($opts['enable_metrics'])) {
                AIBlogger_Utils::log_usage_event([
                    'label' => 'Editor: Reescribir texto',
                    'type'  => 'text',
                    'model' => $model,
                    'usage' => $usage_norm,
                ], 200000);

                if ($post_id > 0) {
                    AIBlogger_Utils::add_usage_to_post_metrics($post_id, [
                        'type' => 'text',
                        'label' => 'Editor: Reescribir texto',
                        'model' => $model,
                        'usage' => $usage_norm,
                    ], [
                        'text_model' => $model,
                    ]);
                }
            }

            wp_send_json_success([
                'text' => $out,
                'usage' => $usage_norm,
                'budget' => $budget,
            ]);
        } catch (Throwable $e) {
            self::fail_with_exception($e, 'editor_rewrite');
        }
    }

    public static function editor_image(): void {
        try {
            self::check_nonce();
            if (!AIBlogger_Utils::can_generate()) {
                wp_send_json_error(['message' => 'Sin permisos.'], 403);
            }

            @set_time_limit(360);
            if (function_exists('wp_raise_memory_limit')) {
                wp_raise_memory_limit('image');
            }

            $opts = AIBlogger_Utils::get_options();
            if (empty($opts['api_key'])) {
                wp_send_json_error(['message' => 'Configura la API Key en Configuracion.'], 400);
            }

            $budget = self::enforce_budget_or_fail();

            $post_id = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
            if ($post_id > 0 && !current_user_can('edit_post', $post_id)) {
                wp_send_json_error(['message' => 'Sin permisos para editar este post.'], 403);
            }

            $scene = isset($_POST['scene']) ? sanitize_text_field((string)wp_unslash($_POST['scene'])) : '';
            $alt = isset($_POST['alt']) ? sanitize_text_field((string)wp_unslash($_POST['alt'])) : '';
            if ($scene === '') {
                wp_send_json_error(['message' => 'Falta la descripcion de escena.'], 400);
            }

            $image_model = (string)($opts['image_model'] ?? 'gemini-2.5-flash-image');
            $ar = AIBlogger_Utils::normalize_aspect_ratio_for_model($image_model, (string)($opts['internal_aspect_ratio'] ?? '4:5'));
            $image_size = AIBlogger_Utils::normalize_image_size_for_model($image_model, (string)($opts['image_size'] ?? '1K'));

            $img_prompt = AIBlogger_Content_Builder::build_internal_image_prompt($scene, [
                'internal_aspect_ratio' => $ar,
            ]);

            $client = new AIBlogger_Gemini_Client((string)$opts['api_key']);
            $img_cfg = ['count' => 1, 'aspectRatio' => $ar];
            if ($image_size) $img_cfg['imageSize'] = $image_size;

            $img_res = $client->generate_images($image_model, $img_prompt, $img_cfg);
            if (is_wp_error($img_res) || empty($img_res['images'][0]['bytes'])) {
                $err = is_wp_error($img_res) ? $img_res->get_error_message() : 'No se genero imagen.';
                wp_send_json_error(['message' => $err, 'details' => is_wp_error($img_res) ? $img_res->get_error_data() : []], 500);
            }

            $aid = AIBlogger_Image_Handler::save_image_bytes(
                (string)$img_res['images'][0]['bytes'],
                (string)($img_res['images'][0]['mime'] ?? 'image/png'),
                $scene
            );
            if (is_wp_error($aid)) {
                wp_send_json_error(['message' => $aid->get_error_message(), 'details' => $aid->get_error_data()], 500);
            }

            if ($alt) {
                update_post_meta((int)$aid, '_wp_attachment_image_alt', $alt);
            }

            $usage_norm = AIBlogger_Utils::normalize_usage(is_array($img_res['usage'] ?? null) ? ($img_res['usage'] ?? []) : []);

            if (!empty($opts['enable_metrics'])) {
                AIBlogger_Utils::log_usage_event([
                    'label' => 'Editor: Generar imagen',
                    'type'  => 'image',
                    'model' => $image_model,
                    'usage' => $usage_norm,
                ], 200000);

                if ($post_id > 0) {
                    AIBlogger_Utils::add_usage_to_post_metrics($post_id, [
                        'type' => 'image',
                        'label' => 'Editor: Generar imagen',
                        'model' => $image_model,
                        'usage' => $usage_norm,
                    ], [
                        'image_model' => $image_model,
                    ]);
                }
            }

            $size_slug = AIBlogger_Image_Handler::get_image_url((int)$aid, 'aiblogger_opt') ? 'aiblogger_opt' : 'large';
            $url = AIBlogger_Image_Handler::get_image_url((int)$aid, $size_slug) ?: AIBlogger_Image_Handler::get_image_url((int)$aid, 'full');
            $html = AIBlogger_Image_Handler::get_img_html((int)$aid, $size_slug, $alt);

            wp_send_json_success([
                'attachment_id' => (int)$aid,
                'url' => (string)$url,
                'alt' => $alt,
                'size_slug' => $size_slug,
                'html' => $html,
                'usage' => $usage_norm,
                'budget' => $budget,
            ]);
        } catch (Throwable $e) {
            self::fail_with_exception($e, 'editor_image');
        }
    }
}
