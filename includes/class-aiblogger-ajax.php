<?php
defined('ABSPATH') || exit;

class AIBlogger_Ajax extends AIBlogger_Ajax_Controller {
    private const GENERATE_JOB_TTL = 7200;

    public static function init(): void {
        add_action('wp_ajax_aiblogger_generate', [__CLASS__, 'generate']);
        add_action('wp_ajax_aiblogger_generate_start', [__CLASS__, 'generate_start']);
        add_action('wp_ajax_aiblogger_generate_status', [__CLASS__, 'generate_status']);
        add_action('wp_ajax_aiblogger_generate_step_image', [__CLASS__, 'generate_step_image']);
        add_action('wp_ajax_aiblogger_generate_finish', [__CLASS__, 'generate_finish']);
        add_action('wp_ajax_aiblogger_reset_defaults', [__CLASS__, 'reset_defaults']);
        add_action('wp_ajax_aiblogger_generate_site_context_ai', [__CLASS__, 'generate_site_context_ai']);
    }

    private static function worker_budget_status() {
        $budget = AIBlogger_Utils::site_token_budget_status();
        if (!empty($budget['exceeded']) && ($budget['mode'] ?? 'warn') === 'block') {
            return new WP_Error('aiblogger_budget_block', self::budget_message($budget), ['status' => 429]);
        }
        return $budget;
    }

    private static function fail_with_wp_error(WP_Error $err, int $default_status = 500): void {
        $data = $err->get_error_data();
        $status = $default_status;
        if (is_array($data) && isset($data['status'])) {
            $status = (int)$data['status'];
        }
        wp_send_json_error([
            'message' => $err->get_error_message(),
            'details' => $data,
        ], $status);
    }

    private static function sanitize_job_token(string $raw): string {
        $token = preg_replace('/[^a-z0-9]/i', '', strtolower(trim($raw)));
        if (!is_string($token)) return '';
        if (strlen($token) < 12) return '';
        if (strlen($token) > 64) {
            $token = substr($token, 0, 64);
        }
        return $token;
    }

    private static function generation_job_key(string $token): string {
        return 'aiblogger_gen_job_' . $token;
    }

    private static function create_generation_job(array $job): string {
        $token = self::sanitize_job_token(wp_generate_password(32, false, false));
        if ($token === '') {
            $token = md5(uniqid((string)mt_rand(), true));
        }
        set_transient(self::generation_job_key($token), $job, self::GENERATE_JOB_TTL);
        return $token;
    }

    private static function get_generation_job(string $token): ?array {
        $raw = get_transient(self::generation_job_key($token));
        return is_array($raw) ? $raw : null;
    }

    private static function save_generation_job(string $token, array $job): void {
        set_transient(self::generation_job_key($token), $job, self::GENERATE_JOB_TTL);
    }

    private static function delete_generation_job(string $token): void {
        delete_transient(self::generation_job_key($token));
    }

    private static function get_generation_job_from_request_or_fail(): array {
        $raw = isset($_POST['job_token']) ? (string)wp_unslash($_POST['job_token']) : '';
        $token = self::sanitize_job_token($raw);
        if ($token === '') {
            wp_send_json_error(['message' => 'Token de trabajo invalido.'], 400);
        }

        $job = self::get_generation_job($token);
        if (!is_array($job)) {
            wp_send_json_error(['message' => 'La sesion de generacion expiro. Inicia de nuevo.'], 404);
        }

        $owner_id = (int)($job['owner_id'] ?? 0);
        if ($owner_id > 0 && get_current_user_id() !== $owner_id && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'No puedes continuar esta generacion.'], 403);
        }

        return [
            'token' => $token,
            'job' => $job,
        ];
    }

    private static function image_progress_from_job(array $job): array {
        $featured_pending = !empty($job['featured_pending']) ? 1 : 0;
        $internal_queue = is_array($job['internal_queue'] ?? null) ? $job['internal_queue'] : [];
        $remaining = $featured_pending + count($internal_queue);

        $internal_images = is_array($job['internal_images'] ?? null) ? $job['internal_images'] : [];
        $generated = count($internal_images) + ((int)($job['featured_attachment_id'] ?? 0) > 0 ? 1 : 0);

        $planned = (int)($job['image_total_planned'] ?? 0);
        if ($planned <= 0) {
            $planned = $generated + $remaining;
        } elseif ($planned < ($generated + $remaining)) {
            $planned = $generated + $remaining;
        }

        $attempted = max(0, $planned - $remaining);

        return [
            'planned' => (int)$planned,
            'generated' => (int)$generated,
            'remaining' => (int)$remaining,
            'attempted' => (int)$attempted,
        ];
    }

    private static function push_job_warning(array &$job, string $warning): void {
        $warning = trim($warning);
        if ($warning === '') return;
        $warnings = is_array($job['warnings_runtime'] ?? null) ? $job['warnings_runtime'] : [];
        $warnings[] = $warning;
        $warnings = array_values(array_unique(array_filter(array_map('strval', $warnings))));
        $job['warnings_runtime'] = $warnings;
    }

    private static function build_internal_briefs_queue(array $gen, int $internal_count): array {
        if ($internal_count <= 0) return [];
        if (empty($gen['internal_image_briefs']) || !is_array($gen['internal_image_briefs'])) return [];

        $queue = [];
        foreach (array_slice($gen['internal_image_briefs'], 0, $internal_count) as $b) {
            if (!is_array($b)) continue;
            $scene = sanitize_text_field((string)($b['scene'] ?? ''));
            if ($scene === '') continue;
            $queue[] = [
                'scene' => $scene,
                'alt' => sanitize_text_field((string)($b['alt'] ?? '')),
                'anchor_heading' => sanitize_text_field((string)($b['anchor_heading'] ?? '')),
            ];
        }

        return $queue;
    }

    private static function queue_max_attempts(): int {
        $raw = (int)apply_filters('aiblogger_queue_max_attempts', 5);
        return max(1, min(20, $raw));
    }

    private static function queue_retry_delay(int $attempts): int {
        $attempts = max(1, $attempts);
        $delay = 5 * (int)pow(2, $attempts - 1);
        return max(5, min(300, $delay));
    }

    private static function job_warnings(array $job): array {
        $out = [];
        if (!empty($job['warnings_runtime']) && is_array($job['warnings_runtime'])) {
            foreach ($job['warnings_runtime'] as $w) {
                $w = sanitize_text_field((string)$w);
                if ($w !== '') $out[] = $w;
            }
        }
        if (!empty($job['gen']['warnings']) && is_array($job['gen']['warnings'])) {
            foreach ($job['gen']['warnings'] as $w) {
                $w = sanitize_text_field((string)$w);
                if ($w !== '') $out[] = $w;
            }
        }
        return array_values(array_unique($out));
    }

    private static function queue_fields_from_job(array $job): array {
        $progress = self::image_progress_from_job($job);
        $timings = is_array($job['timings'] ?? null) ? $job['timings'] : [];
        $text_s = (float)($timings['text'] ?? 0);
        $image_s = (float)($timings['images'] ?? 0);
        $publish_s = (float)($timings['publish'] ?? 0);
        $total_s = $text_s + $image_s + $publish_s;

        return [
            'text_model' => sanitize_text_field((string)($job['text_model'] ?? '')),
            'image_model' => sanitize_text_field((string)($job['image_model'] ?? '')),
            'images_planned' => (int)($progress['planned'] ?? 0),
            'images_generated' => (int)($progress['generated'] ?? 0),
            'images_attempted' => (int)($progress['attempted'] ?? 0),
            'warnings_json' => wp_json_encode(self::job_warnings($job)),
            'text_seconds' => round($text_s, 3),
            'image_seconds' => round($image_s, 3),
            'publish_seconds' => round($publish_s, 3),
            'total_seconds' => round($total_s, 3),
        ];
    }

    private static function queue_phase_from_job(array $job): string {
        $progress = self::image_progress_from_job($job);
        if ((int)$progress['remaining'] > 0) return 'images';
        return 'publish';
    }

    private static function process_job_image_step_internal(array &$job, array $opts) {
        $budget = self::worker_budget_status();
        if (is_wp_error($budget)) return $budget;
        $job['budget'] = $budget;

        if (empty($opts['api_key'])) {
            return new WP_Error('aiblogger_no_key', 'Configura la API Key en Configuracion.');
        }

        $image_model = sanitize_text_field((string)($job['image_model'] ?? ($opts['image_model'] ?? 'gemini-2.5-flash-image')));
        $image_size = AIBlogger_Utils::normalize_image_size_for_model($image_model, (string)($job['image_size'] ?? ($opts['image_size'] ?? '1K')));
        $client = new AIBlogger_Gemini_Client((string)$opts['api_key']);

        if (!empty($job['featured_pending'])) {
            $topic = sanitize_text_field((string)($job['topic'] ?? ''));
            $language = sanitize_text_field((string)($job['language'] ?? 'es'));
            $scene = sanitize_text_field((string)($job['featured_scene'] ?? ''));
            $featured_ar = AIBlogger_Utils::normalize_aspect_ratio_for_model($image_model, (string)($job['featured_aspect_ratio'] ?? '16:9'));

            $img_prompt = AIBlogger_Content_Builder::build_featured_image_prompt($scene ?: $topic, [
                'featured_aspect_ratio' => $featured_ar,
                'language' => $language,
            ]);
            $img_cfg = ['count' => 1, 'aspectRatio' => $featured_ar];
            if ($image_size) $img_cfg['imageSize'] = $image_size;

            $img_res = $client->generate_images($image_model, $img_prompt, $img_cfg);
            if (is_wp_error($img_res) || empty($img_res['images'][0]['bytes'])) {
                return new WP_Error('aiblogger_featured_image_failed', is_wp_error($img_res) ? $img_res->get_error_message() : 'No se pudo generar la imagen destacada.');
            }

            $usage = AIBlogger_Utils::normalize_usage(is_array($img_res['usage'] ?? null) ? ($img_res['usage'] ?? []) : []);
            $calls = is_array($job['calls'] ?? null) ? $job['calls'] : [];
            $calls[] = [
                'type' => 'image',
                'label' => 'Imagen destacada',
                'model' => $image_model,
                'usage' => $usage,
            ];
            $job['calls'] = $calls;

            $aid = AIBlogger_Image_Handler::save_image_bytes(
                (string)$img_res['images'][0]['bytes'],
                (string)($img_res['images'][0]['mime'] ?? 'image/png'),
                (string)($job['gen']['title'] ?? 'featured')
            );
            if (is_wp_error($aid)) {
                return new WP_Error('aiblogger_featured_image_save_failed', $aid->get_error_message());
            }

            $job['featured_attachment_id'] = (int)$aid;
            $job['featured_pending'] = 0;
            return ['step_label' => 'Imagen destacada'];
        }

        $queue = is_array($job['internal_queue'] ?? null) ? array_values($job['internal_queue']) : [];
        if (empty($queue)) {
            return ['step_label' => 'Sin imagenes pendientes'];
        }

        $brief = is_array($queue[0] ?? null) ? $queue[0] : [];
        $scene = sanitize_text_field((string)($brief['scene'] ?? ''));
        $alt = sanitize_text_field((string)($brief['alt'] ?? ''));
        $anchor_heading = sanitize_text_field((string)($brief['anchor_heading'] ?? ''));
        $step_index = (int)($job['internal_index'] ?? 0) + 1;
        $step_label = 'Imagen interna #' . $step_index;

        if ($scene === '') {
            return new WP_Error('aiblogger_internal_brief_invalid', 'Brief de imagen interna invalido.');
        }

        $internal_ar = AIBlogger_Utils::normalize_aspect_ratio_for_model($image_model, (string)($job['internal_aspect_ratio'] ?? '4:5'));
        $img_prompt = AIBlogger_Content_Builder::build_internal_image_prompt($scene, [
            'internal_aspect_ratio' => $internal_ar,
        ]);
        $img_cfg = ['count' => 1, 'aspectRatio' => $internal_ar];
        if ($image_size) $img_cfg['imageSize'] = $image_size;

        $img_res = $client->generate_images($image_model, $img_prompt, $img_cfg);
        if (is_wp_error($img_res) || empty($img_res['images'][0]['bytes'])) {
            return new WP_Error('aiblogger_internal_image_failed', is_wp_error($img_res) ? $img_res->get_error_message() : 'No se pudo generar una imagen interna.');
        }

        $usage = AIBlogger_Utils::normalize_usage(is_array($img_res['usage'] ?? null) ? ($img_res['usage'] ?? []) : []);
        $calls = is_array($job['calls'] ?? null) ? $job['calls'] : [];
        $calls[] = [
            'type' => 'image',
            'label' => $step_label,
            'model' => $image_model,
            'usage' => $usage,
        ];
        $job['calls'] = $calls;

        $aid = AIBlogger_Image_Handler::save_image_bytes(
            (string)$img_res['images'][0]['bytes'],
            (string)($img_res['images'][0]['mime'] ?? 'image/png'),
            $scene
        );
        if (is_wp_error($aid)) {
            return new WP_Error('aiblogger_internal_image_save_failed', $aid->get_error_message());
        }

        if ($alt) {
            update_post_meta((int)$aid, '_wp_attachment_image_alt', $alt);
        }

        $internal_images = is_array($job['internal_images'] ?? null) ? $job['internal_images'] : [];
        $internal_images[] = [
            'attachment_id' => (int)$aid,
            'alt' => $alt,
            'anchor_heading' => $anchor_heading,
        ];
        $job['internal_images'] = $internal_images;
        $job['internal_queue'] = array_slice($queue, 1);
        $job['internal_index'] = $step_index;

        return ['step_label' => $step_label];
    }

    private static function finalize_generation_job_internal(array &$job) {
        $gen = is_array($job['gen'] ?? null) ? $job['gen'] : [];
        $params = is_array($job['params'] ?? null) ? $job['params'] : [];
        $calls = is_array($job['calls'] ?? null) ? $job['calls'] : [];
        $internal_images = is_array($job['internal_images'] ?? null) ? $job['internal_images'] : [];
        $featured_attachment_id = (int)($job['featured_attachment_id'] ?? 0);

        $metrics = [
            'text_model' => sanitize_text_field((string)($job['text_model'] ?? '')),
            'image_model' => sanitize_text_field((string)($job['image_model'] ?? '')),
            'generated_at' => time(),
            'calls' => $calls,
            'totals' => AIBlogger_Utils::aggregate_usage($calls),
            'breakdown' => AIBlogger_Utils::usage_breakdown($calls),
        ];

        $post_id = AIBlogger_Post_Publisher::create_post(
            $gen,
            $params,
            $featured_attachment_id,
            $internal_images,
            $metrics
        );
        if (is_wp_error($post_id)) return $post_id;

        $warnings = self::job_warnings($job);
        $result = [
            'post_id' => (int)$post_id,
            'edit_url' => get_edit_post_link((int)$post_id, ''),
            'featured_attachment_id' => $featured_attachment_id,
            'internal_images' => $internal_images,
            'metrics' => $metrics,
            'warnings' => $warnings,
            'images_generated' => count($internal_images) + ($featured_attachment_id > 0 ? 1 : 0),
            'image_calls' => (int)($metrics['breakdown']['counts']['image_calls'] ?? 0),
            'budget' => is_array($job['budget'] ?? null) ? $job['budget'] : [],
        ];
        $job['result'] = $result;
        return $result;
    }

    private static function process_claimed_queue_job(array $row): void {
        $id = (int)($row['id'] ?? 0);
        $token = sanitize_text_field((string)($row['job_token'] ?? ''));
        $max_attempts = max(1, (int)($row['max_attempts'] ?? self::queue_max_attempts()));
        if ($id <= 0 || $token === '') return;

        $job = self::get_generation_job($token);
        if (!is_array($job)) {
            AIBlogger_Job_Queue::mark_failed($id, 'expired', 'La sesion de generacion expiro.');
            return;
        }

        $opts = AIBlogger_Utils::get_options();

        try {
            if (empty($job['timings']) || !is_array($job['timings'])) {
                $job['timings'] = ['text' => 0.0, 'images' => 0.0, 'publish' => 0.0];
            }

            $progress = self::image_progress_from_job($job);
            if ((int)$progress['remaining'] > 0) {
                $ts = microtime(true);
                $step = self::process_job_image_step_internal($job, $opts);
                $job['timings']['images'] += max(0, microtime(true) - $ts);
                if (is_wp_error($step)) {
                    throw $step;
                }
                self::save_generation_job($token, $job);
                $fields = self::queue_fields_from_job($job);
                AIBlogger_Job_Queue::mark_waiting($id, self::queue_phase_from_job($job), 1, $fields);
                return;
            }

            $ts = microtime(true);
            $final = self::finalize_generation_job_internal($job);
            $job['timings']['publish'] += max(0, microtime(true) - $ts);
            if (is_wp_error($final)) {
                throw $final;
            }

            $fields = self::queue_fields_from_job($job);
            AIBlogger_Job_Queue::mark_done($id, (int)($final['post_id'] ?? 0), is_array($final['warnings'] ?? null) ? $final['warnings'] : [], $fields);
            $post_id = (int)($final['post_id'] ?? 0);
            if ($post_id > 0) {
                update_post_meta($post_id, '_aiblogger_worker_stats', [
                    'attempts' => (int)($row['attempts'] ?? 0),
                    'text_seconds' => (float)($fields['text_seconds'] ?? 0),
                    'image_seconds' => (float)($fields['image_seconds'] ?? 0),
                    'publish_seconds' => (float)($fields['publish_seconds'] ?? 0),
                    'total_seconds' => (float)($fields['total_seconds'] ?? 0),
                ]);
            }
            self::delete_generation_job($token);
        } catch (Throwable $e) {
            $error = $e instanceof WP_Error ? $e->get_error_message() : $e->getMessage();
            $attempts = max(0, (int)($row['attempts'] ?? 0)) + 1;
            $fields = self::queue_fields_from_job($job);

            if ($attempts >= $max_attempts) {
                $fields['attempts'] = $attempts;
                AIBlogger_Job_Queue::mark_failed($id, self::queue_phase_from_job($job), $error, $fields);
                self::delete_generation_job($token);
                return;
            }

            $fields['attempts'] = $attempts;
            $next = time() + self::queue_retry_delay($attempts);
            AIBlogger_Job_Queue::mark_retry($id, $attempts, self::queue_phase_from_job($job), $error, $next, $fields);
            self::save_generation_job($token, $job);
        }
    }

    public static function process_queue_jobs(int $limit = 1, bool $from_cron = false): int {
        $limit = max(1, min(10, $limit));
        $processed = 0;

        for ($i = 0; $i < $limit; $i++) {
            $row = AIBlogger_Job_Queue::claim_due_job();
            if (!is_array($row)) break;
            $processed++;
            self::process_claimed_queue_job($row);
        }

        if (AIBlogger_Job_Queue::has_active_jobs()) {
            AIBlogger_Job_Queue::schedule_worker($from_cron ? 5 : 2);
        }

        return $processed;
    }

    public static function generate_status(): void {
        try {
            self::check_nonce();
            if (!AIBlogger_Utils::can_generate()) {
                wp_send_json_error(['message' => 'Sin permisos.'], 403);
            }

            $raw = isset($_POST['job_token']) ? (string)wp_unslash($_POST['job_token']) : '';
            $token = self::sanitize_job_token($raw);
            if ($token === '') {
                wp_send_json_error(['message' => 'Token de trabajo invalido.'], 400);
            }

            self::process_queue_jobs(1, false);

            $row = AIBlogger_Job_Queue::get_by_token($token);
            if (!is_array($row)) {
                wp_send_json_error(['message' => 'No se encontro el job solicitado.'], 404);
            }

            $owner = (int)($row['owner_user'] ?? 0);
            if ($owner > 0 && $owner !== get_current_user_id() && !current_user_can('manage_options')) {
                wp_send_json_error(['message' => 'No puedes consultar este job.'], 403);
            }

            $job = self::get_generation_job($token);
            $row_warnings = json_decode((string)($row['warnings_json'] ?? '[]'), true);
            $warnings = is_array($row_warnings) ? $row_warnings : [];
            if (is_array($job)) {
                $warnings = array_merge($warnings, self::job_warnings($job));
            }
            $warnings = array_values(array_unique(array_filter(array_map(function ($w) {
                return sanitize_text_field((string)$w);
            }, $warnings))));

            if (is_array($job)) {
                $progress = self::image_progress_from_job($job);
                $planned = (int)($progress['planned'] ?? 0);
                $generated = (int)($progress['generated'] ?? 0);
                $attempted = (int)($progress['attempted'] ?? 0);
                $remaining = (int)($progress['remaining'] ?? 0);
            } else {
                $planned = (int)($row['images_planned'] ?? 0);
                $generated = (int)($row['images_generated'] ?? 0);
                $attempted = (int)($row['images_attempted'] ?? 0);
                $remaining = max(0, $planned - $attempted);
            }

            $post_id = (int)($row['result_post_id'] ?? 0);
            $payload = [
                'job_token' => $token,
                'queue_id' => (int)($row['id'] ?? 0),
                'status' => (string)($row['status'] ?? 'queued'),
                'phase' => (string)($row['phase'] ?? 'queued'),
                'attempts' => (int)($row['attempts'] ?? 0),
                'max_attempts' => (int)($row['max_attempts'] ?? 0),
                'next_run_ts' => (int)($row['next_run_ts'] ?? 0),
                'last_error' => sanitize_text_field((string)($row['last_error'] ?? '')),
                'planned_images' => $planned,
                'generated_images' => $generated,
                'remaining_images' => $remaining,
                'attempted_images' => $attempted,
                'warnings' => $warnings,
                'post_id' => $post_id,
                'edit_url' => $post_id > 0 ? get_edit_post_link($post_id, '') : '',
                'text_seconds' => (float)($row['text_seconds'] ?? 0),
                'image_seconds' => (float)($row['image_seconds'] ?? 0),
                'publish_seconds' => (float)($row['publish_seconds'] ?? 0),
                'total_seconds' => (float)($row['total_seconds'] ?? 0),
            ];
            wp_send_json_success($payload);
        } catch (Throwable $e) {
            self::fail_with_exception($e, 'generate_status');
        }
    }

    public static function reset_defaults(): void {
        try {
            self::check_nonce();
            if (!AIBlogger_Utils::can_manage()) {
                wp_send_json_error(['message' => 'Sin permisos.'], 403);
            }

            update_option(AIBLOGGER_OPT_KEY, AIBlogger_Utils::defaults());
            wp_send_json_success(['message' => 'Reset OK.']);
        } catch (Throwable $e) {
            self::fail_with_exception($e, 'reset_defaults');
        }
    }

    public static function generate_site_context_ai(): void {
        try {
            self::check_nonce();
            if (!AIBlogger_Utils::can_manage()) {
                wp_send_json_error(['message' => 'Sin permisos.'], 403);
            }

            @set_time_limit(420);

            $opts = AIBlogger_Utils::get_options();
            if (empty($opts['api_key'])) {
                wp_send_json_error(['message' => 'Configura la API Key primero.'], 400);
            }

            $budget = self::enforce_budget_or_fail();

            $raw = AIBlogger_Reference_Fetcher::build_site_context_raw_sources();
            if (is_wp_error($raw)) {
                wp_send_json_error(['message' => $raw->get_error_message()], 500);
            }

            $model = !empty($opts['text_model']) ? (string)$opts['text_model'] : 'gemini-3-flash-preview';
            $client = new AIBlogger_Gemini_Client((string)$opts['api_key']);

            $tpl = AIBlogger_Prompts::get('site-context', 'Analiza y devuelve un contexto editorial.');
            $prompt = AIBlogger_Prompts::render($tpl, [
                'RAW' => (string)$raw,
            ]);

            $res = $client->generate_text_with_usage($model, $prompt, ['temperature' => 0.4]);
            if (is_wp_error($res)) {
                wp_send_json_error(['message' => $res->get_error_message(), 'details' => $res->get_error_data()], 500);
            }

            $site_context = trim((string)($res['text'] ?? ''));
            if (!$site_context) {
                wp_send_json_error(['message' => 'No se pudo generar contexto.'], 500);
            }

            if (!empty($opts['enable_metrics'])) {
                AIBlogger_Utils::log_usage_event([
                    'label' => 'Generar contexto del sitio (Config)',
                    'type'  => 'extra',
                    'model' => $model,
                    'usage' => is_array($res['usage'] ?? null) ? ($res['usage'] ?? []) : [],
                ], 200000);
            }

            $opts['site_context'] = $site_context;
            AIBlogger_Utils::update_options($opts);

            $warn = null;
            if (!empty($budget['exceeded']) && ($budget['mode'] ?? 'warn') === 'warn') {
                $warn = self::budget_message($budget);
            }

            wp_send_json_success([
                'site_context' => $site_context,
                'usage' => AIBlogger_Utils::normalize_usage(is_array($res['usage'] ?? null) ? ($res['usage'] ?? []) : []),
                'budget' => $budget,
                'warning' => $warn,
            ]);
        } catch (Throwable $e) {
            self::fail_with_exception($e, 'generate_site_context_ai');
        }
    }

    public static function generate_start(): void {
        try {
            self::check_nonce();
            if (!AIBlogger_Utils::can_generate()) {
                wp_send_json_error(['message' => 'Sin permisos.'], 403);
            }

            @set_time_limit(420);

            $opts = AIBlogger_Utils::get_options();
            if (empty($opts['api_key'])) {
                wp_send_json_error(['message' => 'Configura la API Key en Configuracion.'], 400);
            }

            $budget = self::enforce_budget_or_fail();

            $topic = isset($_POST['topic']) ? sanitize_text_field((string)$_POST['topic']) : '';
            if (!$topic) {
                wp_send_json_error(['message' => 'Falta la tematica.'], 400);
            }

            $extra = isset($_POST['extra']) ? wp_kses_post((string)wp_unslash($_POST['extra'])) : '';

            $media_ids_raw = isset($_POST['media_ids']) ? wp_unslash((string)$_POST['media_ids']) : '';
            $media_ids = AIBlogger_Utils::parse_media_ids($media_ids_raw, 12);
            if ($media_ids) {
                $docs_context = AIBlogger_Utils::media_context_from_ids($media_ids, 10, 18000);
                if ($docs_context !== '') {
                    $extra = trim($extra . "\n\n" . $docs_context);
                }
            }

            $ref_urls = AIBlogger_Utils::extract_urls_from_text($extra, 10);
            $ref_urls = array_values(array_unique($ref_urls));
            $ref_urls = array_slice($ref_urls, 0, 10);

            $language = isset($_POST['language']) ? sanitize_text_field((string)$_POST['language']) : (string)$opts['default_language'];
            $text_model = isset($_POST['text_model']) ? sanitize_text_field((string)$_POST['text_model']) : (string)$opts['text_model'];
            $image_model = isset($_POST['image_model']) ? sanitize_text_field((string)$_POST['image_model']) : (string)$opts['image_model'];

            $min_words = isset($_POST['min_words']) ? max(100, (int)$_POST['min_words']) : (int)$opts['min_words'];
            $max_words = isset($_POST['max_words']) ? max($min_words, (int)$_POST['max_words']) : (int)$opts['max_words'];

            $post_type = isset($_POST['post_type']) ? sanitize_key((string)$_POST['post_type']) : (string)$opts['post_type'];
            if (!post_type_exists($post_type)) {
                $post_type = 'post';
            }

            $pt_obj = get_post_type_object($post_type);
            if (!$pt_obj) {
                wp_send_json_error(['message' => 'Post type no valido.'], 400);
            }

            $edit_cap = !empty($pt_obj->cap->edit_posts) ? (string)$pt_obj->cap->edit_posts : 'edit_posts';
            if (!current_user_can($edit_cap)) {
                wp_send_json_error(['message' => 'Sin permisos para crear en ese post type.'], 403);
            }

            $post_status = isset($_POST['post_status']) ? sanitize_key((string)$_POST['post_status']) : (string)$opts['post_status'];
            $allowed_statuses = ['draft', 'pending', 'publish', 'private'];
            if (!in_array($post_status, $allowed_statuses, true)) {
                $post_status = 'draft';
            }

            $publish_cap = !empty($pt_obj->cap->publish_posts) ? (string)$pt_obj->cap->publish_posts : 'publish_posts';
            if (($post_status === 'publish' || $post_status === 'private') && !current_user_can($publish_cap)) {
                $post_status = 'draft';
            }

            $gen_featured = !empty($_POST['gen_featured']) ? 1 : 0;
            $featured_ar_raw = isset($_POST['featured_ar']) ? sanitize_text_field((string)$_POST['featured_ar']) : (string)$opts['featured_aspect_ratio'];

            $gen_internal = !empty($_POST['gen_internal']) ? 1 : 0;
            $internal_count = isset($_POST['internal_count']) ? max(0, min(10, (int)$_POST['internal_count'])) : (int)$opts['internal_images_count'];
            if (!$gen_internal) $internal_count = 0;
            $internal_ar_raw = isset($_POST['internal_ar']) ? sanitize_text_field((string)$_POST['internal_ar']) : (string)$opts['internal_aspect_ratio'];

            $image_size_raw = isset($_POST['image_size']) ? sanitize_text_field((string)$_POST['image_size']) : (string)$opts['image_size'];

            $site_context = trim((string)$opts['site_context']);
            if (!$site_context) {
                $site_context = AIBlogger_Reference_Fetcher::build_site_context();
            }

            $ref_infos = [];
            if ($ref_urls) {
                $ref_infos = AIBlogger_Reference_Fetcher::fetch_urls($ref_urls);
            }

            $existing_categories = AIBlogger_Post_Publisher::get_existing_category_names($post_type, 40);

            $enable_internal_links = !empty($opts['enable_internal_links']);
            $internal_links_max = (int)($opts['internal_links_max'] ?? 3);
            $internal_link_candidates = $enable_internal_links && $internal_links_max > 0
                ? AIBlogger_Link_Suggester::suggest_internal($topic, $post_type, max(6, $internal_links_max * 3))
                : [];

            $enable_external_links = !empty($opts['enable_external_links']);
            $external_links_max = (int)($opts['external_links_max'] ?? 2);
            $external_link_candidates = $enable_external_links && $external_links_max > 0
                ? AIBlogger_Utils::external_link_candidates($topic, $ref_infos, $opts, 10)
                : [];

            $params = [
                'topic' => $topic,
                'extra_instructions' => $extra,
                'language' => $language,
                'min_words' => $min_words,
                'max_words' => $max_words,
                'site_context' => $site_context,
                'references' => $ref_infos,
                'reference_urls' => $ref_urls,
                'media_ids' => $media_ids,

                'internal_images_count' => $internal_count,
                'post_type' => $post_type,
                'post_status' => $post_status,

                'featured_aspect_ratio' => $featured_ar_raw,
                'internal_aspect_ratio' => $internal_ar_raw,
                'internal_image_display_size' => $opts['internal_image_display_size'],

                'seo_integrations' => (int)$opts['seo_integrations'],
                'enable_metrics' => (int)$opts['enable_metrics'],
                'reuse_existing_terms' => (int)$opts['reuse_existing_terms'],
                'allow_create_new_tags' => (int)$opts['allow_create_new_tags'],
                'existing_categories' => $existing_categories,

                'enable_internal_links' => (int)$enable_internal_links,
                'internal_links_max' => $internal_links_max,
                'internal_link_candidates' => $internal_link_candidates,
                'enable_external_links' => (int)$enable_external_links,
                'external_links_max' => $external_links_max,
                'external_link_candidates' => $external_link_candidates,
            ];

            $prompt = AIBlogger_Content_Builder::build_prompt($params);
            $params['generation_prompt'] = $prompt;
            $schema = AIBlogger_Content_Builder::schema($internal_count);

            $client = new AIBlogger_Gemini_Client((string)$opts['api_key']);
            $text_t0 = microtime(true);
            $structured = $client->generate_structured($text_model, $prompt, $schema);
            if (is_wp_error($structured)) {
                wp_send_json_error(['message' => $structured->get_error_message(), 'details' => $structured->get_error_data()], 500);
            }
            $text_elapsed = max(0, microtime(true) - $text_t0);

            $gen = is_array($structured['data']) ? $structured['data'] : [];
            $usage_text = AIBlogger_Utils::normalize_usage(is_array($structured['usage'] ?? null) ? ($structured['usage'] ?? []) : []);

            $calls = [[
                'type' => 'text',
                'label' => 'Articulo',
                'model' => $text_model,
                'usage' => $usage_text,
            ]];

            $warnings_runtime = [];
            if (!empty($budget['exceeded']) && ($budget['mode'] ?? 'warn') === 'warn') {
                $warnings_runtime[] = self::budget_message($budget);
            }

            $featured_ar = AIBlogger_Utils::normalize_aspect_ratio_for_model($image_model, $featured_ar_raw);
            $internal_ar = AIBlogger_Utils::normalize_aspect_ratio_for_model($image_model, $internal_ar_raw);
            $image_size = AIBlogger_Utils::normalize_image_size_for_model($image_model, $image_size_raw);

            $internal_queue = self::build_internal_briefs_queue($gen, $internal_count);
            $image_total_planned = (int)$gen_featured + count($internal_queue);

            $job = [
                'owner_id' => (int)get_current_user_id(),
                'created_at' => time(),
                'budget' => $budget,
                'timings' => ['text' => (float)$text_elapsed, 'images' => 0.0, 'publish' => 0.0],

                'topic' => $topic,
                'language' => $language,
                'text_model' => $text_model,
                'image_model' => $image_model,
                'featured_scene' => sanitize_text_field((string)($gen['featured_image_scene'] ?? '')),
                'featured_pending' => $gen_featured ? 1 : 0,
                'featured_aspect_ratio' => $featured_ar,
                'internal_aspect_ratio' => $internal_ar,
                'image_size' => $image_size,

                'internal_queue' => $internal_queue,
                'internal_index' => 0,
                'image_total_planned' => $image_total_planned,

                'featured_attachment_id' => 0,
                'internal_images' => [],
                'calls' => $calls,
                'warnings_runtime' => $warnings_runtime,

                'params' => $params,
                'gen' => $gen,
            ];

            $token = self::create_generation_job($job);
            $progress = self::image_progress_from_job($job);

            $queued = AIBlogger_Job_Queue::enqueue([
                'job_token' => $token,
                'owner_user' => (int)get_current_user_id(),
                'phase' => self::queue_phase_from_job($job),
                'max_attempts' => self::queue_max_attempts(),
                'text_model' => $text_model,
                'image_model' => $image_model,
                'images_planned' => (int)($progress['planned'] ?? 0),
                'next_run_ts' => time(),
            ]);
            if (is_wp_error($queued)) {
                self::delete_generation_job($token);
                self::fail_with_wp_error($queued, 500);
            }
            if (!empty($queued['id'])) {
                AIBlogger_Job_Queue::update_job((int)$queued['id'], self::queue_fields_from_job($job));
            }
            self::process_queue_jobs(1, false);
            $queued_row = AIBlogger_Job_Queue::get_by_token($token);

            $warnings_preview = [];
            if (!empty($gen['warnings']) && is_array($gen['warnings'])) {
                foreach ($gen['warnings'] as $w) {
                    $w = sanitize_text_field((string)$w);
                    if ($w !== '') $warnings_preview[] = $w;
                }
            }
            foreach ($warnings_runtime as $w) {
                $w = sanitize_text_field((string)$w);
                if ($w !== '') $warnings_preview[] = $w;
            }
            $warnings_preview = array_values(array_unique($warnings_preview));

            wp_send_json_success([
                'job_token' => $token,
                'queue_id' => (int)($queued_row['id'] ?? 0),
                'status' => (string)($queued_row['status'] ?? 'queued'),
                'phase' => (string)($queued_row['phase'] ?? self::queue_phase_from_job($job)),
                'attempts' => (int)($queued_row['attempts'] ?? 0),
                'max_attempts' => (int)($queued_row['max_attempts'] ?? self::queue_max_attempts()),
                'title' => sanitize_text_field((string)($gen['title'] ?? '')),
                'usage' => $usage_text,
                'planned_images' => (int)$progress['planned'],
                'generated_images' => (int)$progress['generated'],
                'remaining_images' => (int)$progress['remaining'],
                'attempted_images' => (int)$progress['attempted'],
                'warnings' => $warnings_preview,
                'budget' => $budget,
            ]);
        } catch (Throwable $e) {
            self::fail_with_exception($e, 'generate_start');
        }
    }

    public static function generate_step_image(): void {
        try {
            self::check_nonce();
            if (!AIBlogger_Utils::can_generate()) {
                wp_send_json_error(['message' => 'Sin permisos.'], 403);
            }

            @set_time_limit(300);
            if (function_exists('wp_raise_memory_limit')) {
                wp_raise_memory_limit('image');
            }

            $opts = AIBlogger_Utils::get_options();
            if (empty($opts['api_key'])) {
                wp_send_json_error(['message' => 'Configura la API Key en Configuracion.'], 400);
            }

            $ctx = self::get_generation_job_from_request_or_fail();
            $token = (string)$ctx['token'];
            $job = is_array($ctx['job']) ? $ctx['job'] : [];

            $progress = self::image_progress_from_job($job);
            if ((int)$progress['remaining'] <= 0) {
                wp_send_json_success([
                    'done' => 1,
                    'planned_images' => (int)$progress['planned'],
                    'generated_images' => (int)$progress['generated'],
                    'remaining_images' => 0,
                    'attempted_images' => (int)$progress['attempted'],
                    'budget' => is_array($job['budget'] ?? null) ? $job['budget'] : [],
                ]);
            }

            $image_model = sanitize_text_field((string)($job['image_model'] ?? ($opts['image_model'] ?? 'gemini-2.5-flash-image')));
            $image_size = AIBlogger_Utils::normalize_image_size_for_model($image_model, (string)($job['image_size'] ?? ($opts['image_size'] ?? '1K')));
            $client = new AIBlogger_Gemini_Client((string)$opts['api_key']);

            $step_label = '';
            $step_ok = false;
            $step_warning = '';

            if (!empty($job['featured_pending'])) {
                $topic = sanitize_text_field((string)($job['topic'] ?? ''));
                $language = sanitize_text_field((string)($job['language'] ?? 'es'));
                $scene = sanitize_text_field((string)($job['featured_scene'] ?? ''));
                $featured_ar = AIBlogger_Utils::normalize_aspect_ratio_for_model($image_model, (string)($job['featured_aspect_ratio'] ?? '16:9'));
                $step_label = 'Imagen destacada';

                $img_prompt = AIBlogger_Content_Builder::build_featured_image_prompt($scene ?: $topic, [
                    'featured_aspect_ratio' => $featured_ar,
                    'language' => $language,
                ]);
                $img_cfg = [
                    'count' => 1,
                    'aspectRatio' => $featured_ar,
                ];
                if ($image_size) $img_cfg['imageSize'] = $image_size;

                $img_res = $client->generate_images($image_model, $img_prompt, $img_cfg);
                if (is_wp_error($img_res) || empty($img_res['images'][0]['bytes'])) {
                    $step_warning = 'No se pudo generar la imagen destacada.';
                } else {
                    $usage = AIBlogger_Utils::normalize_usage(is_array($img_res['usage'] ?? null) ? ($img_res['usage'] ?? []) : []);
                    $calls = is_array($job['calls'] ?? null) ? $job['calls'] : [];
                    $calls[] = [
                        'type' => 'image',
                        'label' => $step_label,
                        'model' => $image_model,
                        'usage' => $usage,
                    ];
                    $job['calls'] = $calls;

                    $aid = AIBlogger_Image_Handler::save_image_bytes(
                        (string)$img_res['images'][0]['bytes'],
                        (string)($img_res['images'][0]['mime'] ?? 'image/png'),
                        (string)($job['gen']['title'] ?? 'featured')
                    );
                    if (is_wp_error($aid)) {
                        $step_warning = 'No se pudo guardar la imagen destacada.';
                    } else {
                        $job['featured_attachment_id'] = (int)$aid;
                        $step_ok = true;
                    }
                }

                $job['featured_pending'] = 0;
            } else {
                $queue = is_array($job['internal_queue'] ?? null) ? array_values($job['internal_queue']) : [];
                $brief = !empty($queue) ? array_shift($queue) : [];
                $job['internal_queue'] = $queue;
                $job['internal_index'] = (int)($job['internal_index'] ?? 0) + 1;
                $idx = (int)$job['internal_index'];
                $step_label = 'Imagen interna #' . $idx;

                $scene = is_array($brief) ? sanitize_text_field((string)($brief['scene'] ?? '')) : '';
                $alt = is_array($brief) ? sanitize_text_field((string)($brief['alt'] ?? '')) : '';
                $anchor_heading = is_array($brief) ? sanitize_text_field((string)($brief['anchor_heading'] ?? '')) : '';

                if ($scene === '') {
                    $step_warning = 'No se pudo generar una imagen interna.';
                } else {
                    $internal_ar = AIBlogger_Utils::normalize_aspect_ratio_for_model($image_model, (string)($job['internal_aspect_ratio'] ?? '4:5'));

                    $img_prompt = AIBlogger_Content_Builder::build_internal_image_prompt($scene, [
                        'internal_aspect_ratio' => $internal_ar,
                    ]);
                    $img_cfg = [
                        'count' => 1,
                        'aspectRatio' => $internal_ar,
                    ];
                    if ($image_size) $img_cfg['imageSize'] = $image_size;

                    $img_res = $client->generate_images($image_model, $img_prompt, $img_cfg);
                    if (is_wp_error($img_res) || empty($img_res['images'][0]['bytes'])) {
                        $step_warning = 'No se pudo generar una imagen interna.';
                    } else {
                        $usage = AIBlogger_Utils::normalize_usage(is_array($img_res['usage'] ?? null) ? ($img_res['usage'] ?? []) : []);
                        $calls = is_array($job['calls'] ?? null) ? $job['calls'] : [];
                        $calls[] = [
                            'type' => 'image',
                            'label' => $step_label,
                            'model' => $image_model,
                            'usage' => $usage,
                        ];
                        $job['calls'] = $calls;

                        $aid = AIBlogger_Image_Handler::save_image_bytes(
                            (string)$img_res['images'][0]['bytes'],
                            (string)($img_res['images'][0]['mime'] ?? 'image/png'),
                            $scene
                        );
                        if (is_wp_error($aid)) {
                            $step_warning = 'No se pudo guardar una imagen interna.';
                        } else {
                            if ($alt) {
                                update_post_meta((int)$aid, '_wp_attachment_image_alt', $alt);
                            }
                            $internal_images = is_array($job['internal_images'] ?? null) ? $job['internal_images'] : [];
                            $internal_images[] = [
                                'attachment_id' => (int)$aid,
                                'alt' => $alt,
                                'anchor_heading' => $anchor_heading,
                            ];
                            $job['internal_images'] = $internal_images;
                            $step_ok = true;
                        }
                    }
                }
            }

            if ($step_warning !== '') {
                self::push_job_warning($job, $step_warning);
            }

            self::save_generation_job($token, $job);
            $progress = self::image_progress_from_job($job);

            wp_send_json_success([
                'done' => ((int)$progress['remaining'] <= 0) ? 1 : 0,
                'step_label' => $step_label,
                'step_ok' => $step_ok ? 1 : 0,
                'warning' => $step_warning,
                'planned_images' => (int)$progress['planned'],
                'generated_images' => (int)$progress['generated'],
                'remaining_images' => (int)$progress['remaining'],
                'attempted_images' => (int)$progress['attempted'],
                'budget' => is_array($job['budget'] ?? null) ? $job['budget'] : [],
            ]);
        } catch (Throwable $e) {
            self::fail_with_exception($e, 'generate_step_image');
        }
    }

    public static function generate_finish(): void {
        try {
            self::check_nonce();
            if (!AIBlogger_Utils::can_generate()) {
                wp_send_json_error(['message' => 'Sin permisos.'], 403);
            }

            $ctx = self::get_generation_job_from_request_or_fail();
            $token = (string)$ctx['token'];
            $job = is_array($ctx['job']) ? $ctx['job'] : [];

            $progress = self::image_progress_from_job($job);
            if ((int)$progress['remaining'] > 0) {
                wp_send_json_error([
                    'message' => 'Aun hay imagenes pendientes por procesar.',
                    'details' => $progress,
                ], 409);
            }

            $gen = is_array($job['gen'] ?? null) ? $job['gen'] : [];
            $params = is_array($job['params'] ?? null) ? $job['params'] : [];
            $calls = is_array($job['calls'] ?? null) ? $job['calls'] : [];
            $internal_images = is_array($job['internal_images'] ?? null) ? $job['internal_images'] : [];
            $featured_attachment_id = (int)($job['featured_attachment_id'] ?? 0);

            $metrics = [
                'text_model' => sanitize_text_field((string)($job['text_model'] ?? '')),
                'image_model' => sanitize_text_field((string)($job['image_model'] ?? '')),
                'generated_at' => time(),
                'calls' => $calls,
                'totals' => AIBlogger_Utils::aggregate_usage($calls),
                'breakdown' => AIBlogger_Utils::usage_breakdown($calls),
            ];

            $post_id = AIBlogger_Post_Publisher::create_post(
                $gen,
                $params,
                $featured_attachment_id,
                $internal_images,
                $metrics
            );
            if (is_wp_error($post_id)) {
                self::fail_with_wp_error($post_id, 500);
            }

            self::delete_generation_job($token);

            $warnings = [];
            if (!empty($gen['warnings']) && is_array($gen['warnings'])) {
                foreach ($gen['warnings'] as $w) {
                    $w = sanitize_text_field((string)$w);
                    if ($w !== '') $warnings[] = $w;
                }
            }
            if (!empty($job['warnings_runtime']) && is_array($job['warnings_runtime'])) {
                foreach ($job['warnings_runtime'] as $w) {
                    $w = sanitize_text_field((string)$w);
                    if ($w !== '') $warnings[] = $w;
                }
            }
            $warnings = array_values(array_unique($warnings));

            $image_generated_count = count($internal_images) + ($featured_attachment_id > 0 ? 1 : 0);
            $image_calls = (int)($metrics['breakdown']['counts']['image_calls'] ?? 0);

            wp_send_json_success([
                'post_id' => (int)$post_id,
                'edit_url' => get_edit_post_link($post_id, ''),
                'featured_attachment_id' => $featured_attachment_id,
                'internal_images' => $internal_images,
                'metrics' => $metrics,
                'warnings' => $warnings,
                'images_generated' => (int)$image_generated_count,
                'image_calls' => $image_calls,
                'budget' => is_array($job['budget'] ?? null) ? $job['budget'] : [],
            ]);
        } catch (Throwable $e) {
            self::fail_with_exception($e, 'generate_finish');
        }
    }

    public static function generate(): void {
        try {
        self::check_nonce();
        if (!AIBlogger_Utils::can_generate()) {
            wp_send_json_error(['message' => 'Sin permisos.'], 403);
        }

        @set_time_limit(600);
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('image');
        }

        $opts = AIBlogger_Utils::get_options();
        if (empty($opts['api_key'])) {
            wp_send_json_error(['message' => 'Configura la API Key en Configuracion.'], 400);
        }

        $budget = self::enforce_budget_or_fail();

        $topic = isset($_POST['topic']) ? sanitize_text_field((string)$_POST['topic']) : '';
        if (!$topic) {
            wp_send_json_error(['message' => 'Falta la tematica.'], 400);
        }

        $extra = isset($_POST['extra']) ? wp_kses_post((string)wp_unslash($_POST['extra'])) : '';

        $media_ids_raw = isset($_POST['media_ids']) ? wp_unslash((string)$_POST['media_ids']) : '';
        $media_ids = AIBlogger_Utils::parse_media_ids($media_ids_raw, 12);
        if ($media_ids) {
            $docs_context = AIBlogger_Utils::media_context_from_ids($media_ids, 10, 18000);
            if ($docs_context !== '') {
                $extra = trim($extra . "\n\n" . $docs_context);
            }
        }

        $ref_urls = AIBlogger_Utils::extract_urls_from_text($extra, 10);
        $ref_urls = array_values(array_unique($ref_urls));
        $ref_urls = array_slice($ref_urls, 0, 10);

        $language = isset($_POST['language']) ? sanitize_text_field((string)$_POST['language']) : (string)$opts['default_language'];
        $text_model = isset($_POST['text_model']) ? sanitize_text_field((string)$_POST['text_model']) : (string)$opts['text_model'];
        $image_model = isset($_POST['image_model']) ? sanitize_text_field((string)$_POST['image_model']) : (string)$opts['image_model'];

        $min_words = isset($_POST['min_words']) ? max(100, (int)$_POST['min_words']) : (int)$opts['min_words'];
        $max_words = isset($_POST['max_words']) ? max($min_words, (int)$_POST['max_words']) : (int)$opts['max_words'];

        $post_type = isset($_POST['post_type']) ? sanitize_key((string)$_POST['post_type']) : (string)$opts['post_type'];
        if (!post_type_exists($post_type)) {
            $post_type = 'post';
        }

        $pt_obj = get_post_type_object($post_type);
        if (!$pt_obj) {
            wp_send_json_error(['message' => 'Post type no valido.'], 400);
        }

        $edit_cap = !empty($pt_obj->cap->edit_posts) ? (string)$pt_obj->cap->edit_posts : 'edit_posts';
        if (!current_user_can($edit_cap)) {
            wp_send_json_error(['message' => 'Sin permisos para crear en ese post type.'], 403);
        }

        $post_status = isset($_POST['post_status']) ? sanitize_key((string)$_POST['post_status']) : (string)$opts['post_status'];
        $allowed_statuses = ['draft', 'pending', 'publish', 'private'];
        if (!in_array($post_status, $allowed_statuses, true)) {
            $post_status = 'draft';
        }

        $publish_cap = !empty($pt_obj->cap->publish_posts) ? (string)$pt_obj->cap->publish_posts : 'publish_posts';
        if (($post_status === 'publish' || $post_status === 'private') && !current_user_can($publish_cap)) {
            $post_status = 'draft';
        }

        $gen_featured = !empty($_POST['gen_featured']) ? 1 : 0;
        $featured_ar_raw = isset($_POST['featured_ar']) ? sanitize_text_field((string)$_POST['featured_ar']) : (string)$opts['featured_aspect_ratio'];

        $gen_internal = !empty($_POST['gen_internal']) ? 1 : 0;
        $internal_count = isset($_POST['internal_count']) ? max(0, min(10, (int)$_POST['internal_count'])) : (int)$opts['internal_images_count'];
        if (!$gen_internal) $internal_count = 0;
        $internal_ar_raw = isset($_POST['internal_ar']) ? sanitize_text_field((string)$_POST['internal_ar']) : (string)$opts['internal_aspect_ratio'];

        $image_size_raw = isset($_POST['image_size']) ? sanitize_text_field((string)$_POST['image_size']) : (string)$opts['image_size'];

        $site_context = trim((string)$opts['site_context']);
        if (!$site_context) {
            $site_context = AIBlogger_Reference_Fetcher::build_site_context();
        }

        $ref_infos = [];
        if ($ref_urls) {
            $ref_infos = AIBlogger_Reference_Fetcher::fetch_urls($ref_urls);
        }

        $existing_categories = AIBlogger_Post_Publisher::get_existing_category_names($post_type, 40);

        $enable_internal_links = !empty($opts['enable_internal_links']);
        $internal_links_max = (int)($opts['internal_links_max'] ?? 3);
        $internal_link_candidates = $enable_internal_links && $internal_links_max > 0
            ? AIBlogger_Link_Suggester::suggest_internal($topic, $post_type, max(6, $internal_links_max * 3))
            : [];

        $enable_external_links = !empty($opts['enable_external_links']);
        $external_links_max = (int)($opts['external_links_max'] ?? 2);
        $external_link_candidates = $enable_external_links && $external_links_max > 0
            ? AIBlogger_Utils::external_link_candidates($topic, $ref_infos, $opts, 10)
            : [];

        $params = [
            'topic' => $topic,
            'extra_instructions' => $extra,
            'language' => $language,
            'min_words' => $min_words,
            'max_words' => $max_words,
            'site_context' => $site_context,
            'references' => $ref_infos,
            'reference_urls' => $ref_urls,
            'media_ids' => $media_ids,

            'internal_images_count' => $internal_count,
            'post_type' => $post_type,
            'post_status' => $post_status,

            'featured_aspect_ratio' => $featured_ar_raw,
            'internal_aspect_ratio' => $internal_ar_raw,
            'internal_image_display_size' => $opts['internal_image_display_size'],

            'seo_integrations' => (int)$opts['seo_integrations'],
            'enable_metrics' => (int)$opts['enable_metrics'],
            'reuse_existing_terms' => (int)$opts['reuse_existing_terms'],
            'allow_create_new_tags' => (int)$opts['allow_create_new_tags'],
            'existing_categories' => $existing_categories,

            'enable_internal_links' => (int)$enable_internal_links,
            'internal_links_max' => $internal_links_max,
            'internal_link_candidates' => $internal_link_candidates,
            'enable_external_links' => (int)$enable_external_links,
            'external_links_max' => $external_links_max,
            'external_link_candidates' => $external_link_candidates,
        ];

        $prompt = AIBlogger_Content_Builder::build_prompt($params);
        $params['generation_prompt'] = $prompt;
        $schema = AIBlogger_Content_Builder::schema($internal_count);

        $client = new AIBlogger_Gemini_Client((string)$opts['api_key']);
        $calls = [];

        $structured = $client->generate_structured($text_model, $prompt, $schema);
        if (is_wp_error($structured)) {
            wp_send_json_error(['message' => $structured->get_error_message(), 'details' => $structured->get_error_data()], 500);
        }

        $gen = is_array($structured['data']) ? $structured['data'] : [];

        $usage_text = AIBlogger_Utils::normalize_usage(is_array($structured['usage'] ?? null) ? ($structured['usage'] ?? []) : []);
        $calls[] = [
            'type' => 'text',
            'label' => 'Articulo',
            'model' => $text_model,
            'usage' => $usage_text,
        ];

        $warnings_runtime = [];
        if (!empty($budget['exceeded']) && ($budget['mode'] ?? 'warn') === 'warn') {
            $warnings_runtime[] = self::budget_message($budget);
        }

        $featured_ar = AIBlogger_Utils::normalize_aspect_ratio_for_model($image_model, $featured_ar_raw);
        $internal_ar = AIBlogger_Utils::normalize_aspect_ratio_for_model($image_model, $internal_ar_raw);
        $image_size = AIBlogger_Utils::normalize_image_size_for_model($image_model, $image_size_raw);

        $featured_attachment_id = 0;
        if ($gen_featured) {
            $scene = sanitize_text_field((string)($gen['featured_image_scene'] ?? ''));
            $img_prompt = AIBlogger_Content_Builder::build_featured_image_prompt($scene ?: $topic, [
                'featured_aspect_ratio' => $featured_ar,
                'language' => $language,
            ]);

            $img_cfg = [
                'count' => 1,
                'aspectRatio' => $featured_ar,
            ];
            if ($image_size) $img_cfg['imageSize'] = $image_size;

            $img_res = $client->generate_images($image_model, $img_prompt, $img_cfg);

            if (!is_wp_error($img_res) && !empty($img_res['images'][0]['bytes'])) {
                $calls[] = [
                    'type' => 'image',
                    'label' => 'Imagen destacada',
                    'model' => $image_model,
                    'usage' => AIBlogger_Utils::normalize_usage(is_array($img_res['usage'] ?? null) ? ($img_res['usage'] ?? []) : []),
                ];

                $featured_attachment_id = AIBlogger_Image_Handler::save_image_bytes(
                    (string)$img_res['images'][0]['bytes'],
                    (string)($img_res['images'][0]['mime'] ?? 'image/png'),
                    (string)($gen['title'] ?? 'featured')
                );
                if (is_wp_error($featured_attachment_id)) {
                    $warnings_runtime[] = 'No se pudo guardar la imagen destacada.';
                    $featured_attachment_id = 0;
                }
            } else {
                $warnings_runtime[] = 'No se pudo generar la imagen destacada.';
            }
        }

        $internal_images = [];
        if ($internal_count > 0 && !empty($gen['internal_image_briefs']) && is_array($gen['internal_image_briefs'])) {
            $briefs = array_slice($gen['internal_image_briefs'], 0, $internal_count);
            $i = 0;
            foreach ($briefs as $b) {
                $i++;
                $scene = sanitize_text_field((string)($b['scene'] ?? ''));
                $alt = sanitize_text_field((string)($b['alt'] ?? ''));
                if (!$scene) continue;

                $img_prompt = AIBlogger_Content_Builder::build_internal_image_prompt($scene, [
                    'internal_aspect_ratio' => $internal_ar,
                ]);

                $img_cfg = [
                    'count' => 1,
                    'aspectRatio' => $internal_ar,
                ];
                if ($image_size) $img_cfg['imageSize'] = $image_size;

                $img_res = $client->generate_images($image_model, $img_prompt, $img_cfg);

                if (is_wp_error($img_res) || empty($img_res['images'][0]['bytes'])) {
                    $warnings_runtime[] = 'No se pudo generar una imagen interna.';
                    continue;
                }

                $calls[] = [
                    'type' => 'image',
                    'label' => 'Imagen interna #' . $i,
                    'model' => $image_model,
                    'usage' => AIBlogger_Utils::normalize_usage(is_array($img_res['usage'] ?? null) ? ($img_res['usage'] ?? []) : []),
                ];

                $aid = AIBlogger_Image_Handler::save_image_bytes(
                    (string)$img_res['images'][0]['bytes'],
                    (string)($img_res['images'][0]['mime'] ?? 'image/png'),
                    $scene
                );
                if (is_wp_error($aid)) {
                    $warnings_runtime[] = 'No se pudo guardar una imagen interna.';
                    continue;
                }

                if ($alt) {
                    update_post_meta((int)$aid, '_wp_attachment_image_alt', $alt);
                }

                $internal_images[] = [
                    'attachment_id' => (int)$aid,
                    'alt' => $alt,
                    'anchor_heading' => sanitize_text_field((string)($b['anchor_heading'] ?? '')),
                ];
            }
        }

        $metrics = [
            'text_model' => $text_model,
            'image_model' => $image_model,
            'generated_at' => time(),
            'calls' => $calls,
            'totals' => AIBlogger_Utils::aggregate_usage($calls),
            'breakdown' => AIBlogger_Utils::usage_breakdown($calls),
        ];

        $post_id = AIBlogger_Post_Publisher::create_post(
            $gen,
            $params,
            (int)$featured_attachment_id,
            $internal_images,
            $metrics
        );

        if (is_wp_error($post_id)) {
            wp_send_json_error(['message' => $post_id->get_error_message(), 'details' => $post_id->get_error_data()], 500);
        }

        $edit_url = get_edit_post_link($post_id, '');

        $warnings = [];
        if (!empty($gen['warnings']) && is_array($gen['warnings'])) {
            $warnings = array_merge($warnings, $gen['warnings']);
        }
        if ($warnings_runtime) {
            $warnings = array_merge($warnings, $warnings_runtime);
        }

        wp_send_json_success([
            'post_id' => (int)$post_id,
            'edit_url' => $edit_url,
            'featured_attachment_id' => (int)$featured_attachment_id,
            'internal_images' => $internal_images,
            'metrics' => $metrics,
            'warnings' => $warnings,
            'budget' => $budget,
        ]);
        } catch (Throwable $e) {
            self::fail_with_exception($e, 'generate');
        }
    }
}
