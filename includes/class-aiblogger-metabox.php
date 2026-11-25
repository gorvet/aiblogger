<?php
defined('ABSPATH') || exit;

class AIBlogger_Metabox {

    public static function init() {
        add_action('add_meta_boxes', [__CLASS__, 'register']);
    }

    public static function register() {
        if (!AIBlogger_Utils::can_generate()) return;

        $post_types = get_post_types(['show_ui' => true], 'names');
        foreach ($post_types as $pt) {
            add_meta_box(
                'aiblogger_metrics_box',
                'AIBlogger: Metricas + Editor IA',
                [__CLASS__, 'render'],
                $pt,
                'side',
                'default'
            );
        }
    }

    private static function render_editor_tools($post): void {
        $opts = AIBlogger_Utils::get_options();

        echo '<p><strong>Asistente IA en editor</strong></p>';
        echo '<p class="description">Gutenberg: funciona sobre bloque seleccionado. Classic: funciona sobre texto seleccionado.</p>';

        if (empty($opts['api_key'])) {
            echo '<p class="description">Configura API Key para activar estas herramientas.</p>';
            return;
        }

        echo '<p><label for="aib_editor_instruction"><strong>Instruccion de reescritura</strong></label><br>';
        echo '<textarea id="aib_editor_instruction" rows="3" style="width:100%;" placeholder="Ej: mas corto, mas formal, mas tecnico..."></textarea></p>';
        echo '<p><button type="button" class="button" id="aib_editor_rewrite_btn">Reescribir seleccion</button></p>';

        echo '<hr>';

        echo '<p><label for="aib_editor_scene"><strong>Imagen IA (insertar o reemplazar)</strong></label><br>';
        echo '<textarea id="aib_editor_scene" rows="3" style="width:100%;" placeholder="Describe la escena de la imagen..."></textarea></p>';
        echo '<p><label for="aib_editor_alt">Alt (opcional)</label><br>';
        echo '<input type="text" id="aib_editor_alt" style="width:100%;" /></p>';
        echo '<p><button type="button" class="button" id="aib_editor_image_btn">Generar imagen e insertar</button></p>';

        echo '<p id="aib_editor_status" class="description"></p>';
    }

    public static function render($post) {
        self::render_editor_tools($post);

        $metrics = get_post_meta($post->ID, '_aiblogger_metrics', true);
        $refs = get_post_meta($post->ID, '_aiblogger_reference_urls', true);
        $warnings = get_post_meta($post->ID, '_aiblogger_warnings', true);

        echo '<hr><p><strong>Metricas del post</strong></p>';

        if (!is_array($metrics) || empty($metrics)) {
            echo '<p class="description">Este post no tiene metricas registradas por AIBlogger.</p>';
            return;
        }

        $prompt = (int)($metrics['prompt_tokens'] ?? 0);
        $out = (int)($metrics['output_tokens'] ?? 0);
        $tool = (int)($metrics['tool_tokens'] ?? 0);
        $thought = (int)($metrics['thought_tokens'] ?? 0);
        $cached = (int)($metrics['cached_tokens'] ?? 0);
        $total = (int)($metrics['total_tokens'] ?? 0);

        $text_total = (int)($metrics['text_total_tokens'] ?? 0);
        $text_in = (int)($metrics['text_prompt_tokens'] ?? 0);
        $text_out = (int)($metrics['text_output_tokens'] ?? 0);

        $img_total = (int)($metrics['image_total_tokens'] ?? 0);
        $img_in = (int)($metrics['image_prompt_tokens'] ?? 0);
        $img_out = (int)($metrics['image_output_tokens'] ?? 0);

        $extra_total = (int)($metrics['extra_total_tokens'] ?? 0);
        $extra_in = (int)($metrics['extra_prompt_tokens'] ?? 0);
        $extra_out = (int)($metrics['extra_output_tokens'] ?? 0);

        $img_calls = (int)($metrics['image_calls'] ?? 0);

        echo '<p><strong>Modelo texto:</strong><br>' . esc_html((string)($metrics['text_model'] ?? '')) . '</p>';
        echo '<p><strong>Modelo imagen:</strong><br>' . esc_html((string)($metrics['image_model'] ?? '')) . '</p>';

        echo '<p><strong>Tokens (total):</strong> ' . esc_html((string)$total) . '<br>'
            . '<span class="description">Prompt ' . esc_html((string)$prompt)
            . ' | Salida ' . esc_html((string)$out)
            . ($tool ? ' | Tool ' . esc_html((string)$tool) : '')
            . ($thought ? ' | Thought ' . esc_html((string)$thought) : '')
            . ($cached ? ' | Cached ' . esc_html((string)$cached) : '')
            . '</span></p>';

        $estimated_usd = AIBlogger_Utils::estimate_usage_cost_usd([
            'prompt_tokens' => $prompt,
            'output_tokens' => $out,
            'tool_tokens' => $tool,
            'thought_tokens' => $thought,
            'cached_tokens' => $cached,
        ], (string)($metrics['text_model'] ?? $metrics['image_model'] ?? ''));
        echo '<p><strong>Costo estimado:</strong> USD ' . esc_html((string)$estimated_usd) . '</p>';

        echo '<p><strong>Texto:</strong> ' . esc_html((string)$text_total)
            . '<br><span class="description">Entrada ' . esc_html((string)$text_in)
            . ' | Salida ' . esc_html((string)$text_out) . '</span></p>';

        echo '<p><strong>Imagenes:</strong> ' . esc_html((string)$img_total)
            . ($img_calls ? ' <span class="description">(' . esc_html((string)$img_calls) . ' llamadas)</span>' : '')
            . '<br><span class="description">Entrada ' . esc_html((string)$img_in)
            . ' | Salida ' . esc_html((string)$img_out) . '</span></p>';

        if ($extra_total > 0 || $extra_in > 0 || $extra_out > 0) {
            echo '<p><strong>Extra:</strong> ' . esc_html((string)$extra_total)
                . '<br><span class="description">Entrada ' . esc_html((string)$extra_in)
                . ' | Salida ' . esc_html((string)$extra_out) . '</span></p>';
        }

        if (!empty($metrics['generated_at'])) {
            echo '<p class="description">Generado: ' . esc_html(date_i18n('Y-m-d H:i', (int)$metrics['generated_at'])) . '</p>';
        }

        $worker = get_post_meta($post->ID, '_aiblogger_worker_stats', true);
        if (is_array($worker) && !empty($worker)) {
            $attempts = (int)($worker['attempts'] ?? 0);
            $total_s = (float)($worker['total_seconds'] ?? 0);
            echo '<p class="description">Worker: reintentos ' . esc_html((string)$attempts) . ' | tiempo total ' . esc_html((string)$total_s) . 's</p>';
        }

        if (!empty($metrics['calls']) && is_array($metrics['calls'])) {
            echo '<hr><p><strong>Detalle por peticion:</strong></p>';
            echo '<ul style="margin-left:18px;">';
            foreach (array_slice($metrics['calls'], 0, 10) as $c) {
                if (!is_array($c)) continue;
                $label = (string)($c['label'] ?? $c['type'] ?? '');
                $model = (string)($c['model'] ?? '');
                $u = is_array($c['usage'] ?? null) ? $c['usage'] : [];
                $t = (int)($u['total_tokens'] ?? 0);
                echo '<li>' . esc_html($label) . ' - ' . esc_html($model) . ' - ' . esc_html((string)$t) . '</li>';
            }
            echo '</ul>';
        }

        if (is_array($refs) && !empty($refs)) {
            echo '<hr><p><strong>Referencias:</strong></p><ul style="margin-left:18px;">';
            foreach (array_slice($refs, 0, 6) as $u) {
                echo '<li><a href="' . esc_url($u) . '" target="_blank" rel="noopener">' . esc_html($u) . '</a></li>';
            }
            echo '</ul>';
        }

        if (is_array($warnings) && !empty($warnings)) {
            echo '<hr><p><strong>Warnings:</strong></p><ul style="margin-left:18px;">';
            foreach (array_slice($warnings, 0, 5) as $w) {
                echo '<li>' . esc_html($w) . '</li>';
            }
            echo '</ul>';
        }
    }
}
