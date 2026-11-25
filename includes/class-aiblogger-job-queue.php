<?php
defined('ABSPATH') || exit;

class AIBlogger_Job_Queue {
    public const CRON_HOOK = 'aiblogger_process_queue';

    public static function init(): void {
        add_action(self::CRON_HOOK, [__CLASS__, 'process_cron']);
    }

    public static function process_cron(): void {
        if (class_exists('AIBlogger_Ajax')) {
            AIBlogger_Ajax::process_queue_jobs(2, true);
        }
    }

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'aiblogger_jobs';
    }

    public static function ensure_table(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_token VARCHAR(64) NOT NULL,
            owner_user BIGINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'queued',
            phase VARCHAR(40) NOT NULL DEFAULT 'queued',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
            next_run_ts BIGINT UNSIGNED NOT NULL DEFAULT 0,
            locked_until_ts BIGINT UNSIGNED NOT NULL DEFAULT 0,
            text_model VARCHAR(120) NOT NULL DEFAULT '',
            image_model VARCHAR(120) NOT NULL DEFAULT '',
            images_planned INT UNSIGNED NOT NULL DEFAULT 0,
            images_generated INT UNSIGNED NOT NULL DEFAULT 0,
            images_attempted INT UNSIGNED NOT NULL DEFAULT 0,
            result_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            warnings_json LONGTEXT NULL,
            text_seconds DECIMAL(10,3) NOT NULL DEFAULT 0,
            image_seconds DECIMAL(10,3) NOT NULL DEFAULT 0,
            publish_seconds DECIMAL(10,3) NOT NULL DEFAULT 0,
            total_seconds DECIMAL(10,3) NOT NULL DEFAULT 0,
            created_ts BIGINT UNSIGNED NOT NULL DEFAULT 0,
            started_ts BIGINT UNSIGNED NOT NULL DEFAULT 0,
            finished_ts BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_ts BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY job_token (job_token),
            KEY status_next (status, next_run_ts),
            KEY owner_user (owner_user),
            KEY locked_until_ts (locked_until_ts),
            KEY result_post_id (result_post_id)
        ) {$charset};";

        dbDelta($sql);
    }

    public static function enqueue(array $args) {
        self::ensure_table();

        global $wpdb;
        $table = self::table_name();
        $now = time();

        $token = sanitize_text_field((string)($args['job_token'] ?? ''));
        if ($token === '') {
            return new WP_Error('aiblogger_queue_token', 'Token de job invalido.');
        }

        $inserted = $wpdb->insert($table, [
            'job_token' => $token,
            'owner_user' => max(0, (int)($args['owner_user'] ?? 0)),
            'status' => 'queued',
            'phase' => sanitize_key((string)($args['phase'] ?? 'queued')) ?: 'queued',
            'attempts' => 0,
            'max_attempts' => max(1, min(20, (int)($args['max_attempts'] ?? 5))),
            'next_run_ts' => max(0, (int)($args['next_run_ts'] ?? $now)),
            'locked_until_ts' => 0,
            'text_model' => sanitize_text_field((string)($args['text_model'] ?? '')),
            'image_model' => sanitize_text_field((string)($args['image_model'] ?? '')),
            'images_planned' => max(0, (int)($args['images_planned'] ?? 0)),
            'images_generated' => 0,
            'images_attempted' => 0,
            'result_post_id' => 0,
            'last_error' => '',
            'warnings_json' => '[]',
            'text_seconds' => 0,
            'image_seconds' => 0,
            'publish_seconds' => 0,
            'total_seconds' => 0,
            'created_ts' => $now,
            'started_ts' => 0,
            'finished_ts' => 0,
            'updated_ts' => $now,
        ]);

        if (!$inserted) {
            return new WP_Error('aiblogger_queue_insert', 'No se pudo encolar el trabajo.', ['db_error' => $wpdb->last_error]);
        }

        self::schedule_worker(1);
        return self::get_by_token($token);
    }

    public static function get_by_token(string $token): ?array {
        self::ensure_table();
        global $wpdb;
        $table = self::table_name();
        $token = sanitize_text_field($token);
        if ($token === '') return null;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE job_token = %s LIMIT 1", $token), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function get_by_id(int $id): ?array {
        self::ensure_table();
        global $wpdb;
        $table = self::table_name();
        if ($id <= 0) return null;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function claim_due_job(): ?array {
        self::ensure_table();

        global $wpdb;
        $table = self::table_name();
        $now = time();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE status IN ('queued','retrying','running')
               AND next_run_ts <= %d
               AND (locked_until_ts = 0 OR locked_until_ts < %d)
             ORDER BY id ASC
             LIMIT 1",
            $now,
            $now
        ), ARRAY_A);

        if (!is_array($row) || empty($row['id'])) {
            return null;
        }

        $id = (int)$row['id'];
        $status = (string)($row['status'] ?? 'queued');
        $lock_until = $now + 180;

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET status = 'running',
                    locked_until_ts = %d,
                    started_ts = CASE WHEN started_ts = 0 THEN %d ELSE started_ts END,
                    updated_ts = %d
              WHERE id = %d
                AND status = %s
                AND (locked_until_ts = 0 OR locked_until_ts < %d)",
            $lock_until,
            $now,
            $now,
            $id,
            $status,
            $now
        ));

        if (!$updated) {
            return null;
        }

        return self::get_by_id($id);
    }

    public static function update_job(int $id, array $fields): void {
        if ($id <= 0 || empty($fields)) return;
        self::ensure_table();

        global $wpdb;
        $table = self::table_name();
        $fields['updated_ts'] = time();
        $wpdb->update($table, $fields, ['id' => $id]);
    }

    public static function mark_waiting(int $id, string $phase, int $delay_seconds = 1, array $fields = []): void {
        $now = time();
        $phase = sanitize_key($phase);
        $data = array_merge($fields, [
            'status' => 'queued',
            'phase' => $phase ?: 'queued',
            'next_run_ts' => $now + max(1, $delay_seconds),
            'locked_until_ts' => 0,
        ]);
        self::update_job($id, $data);
        self::schedule_worker(1);
    }

    public static function mark_retry(int $id, int $attempts, string $phase, string $error, int $next_run_ts, array $fields = []): void {
        $phase = sanitize_key($phase);
        $data = array_merge($fields, [
            'status' => 'retrying',
            'phase' => $phase ?: 'retrying',
            'attempts' => max(0, $attempts),
            'last_error' => sanitize_text_field($error),
            'next_run_ts' => max(time() + 1, $next_run_ts),
            'locked_until_ts' => 0,
        ]);
        self::update_job($id, $data);
        self::schedule_worker(1);
    }

    public static function mark_failed(int $id, string $phase, string $error, array $fields = []): void {
        $data = array_merge($fields, [
            'status' => 'failed',
            'phase' => sanitize_key($phase) ?: 'failed',
            'last_error' => sanitize_text_field($error),
            'locked_until_ts' => 0,
            'finished_ts' => time(),
            'next_run_ts' => 0,
        ]);
        self::update_job($id, $data);
    }

    public static function mark_done(int $id, int $post_id, array $warnings = [], array $fields = []): void {
        $safe_warnings = [];
        foreach ($warnings as $w) {
            $w = sanitize_text_field((string)$w);
            if ($w !== '') $safe_warnings[] = $w;
        }
        $safe_warnings = array_values(array_unique($safe_warnings));

        $data = array_merge($fields, [
            'status' => 'done',
            'phase' => 'done',
            'result_post_id' => max(0, $post_id),
            'warnings_json' => wp_json_encode($safe_warnings),
            'last_error' => '',
            'locked_until_ts' => 0,
            'finished_ts' => time(),
            'next_run_ts' => 0,
        ]);
        self::update_job($id, $data);
    }

    public static function has_active_jobs(): bool {
        self::ensure_table();
        global $wpdb;
        $table = self::table_name();
        $count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status IN ('queued','retrying','running')");
        return $count > 0;
    }

    public static function schedule_worker(int $delay_seconds = 1): void {
        $delay_seconds = max(1, $delay_seconds);
        $when = time() + $delay_seconds;
        $next = wp_next_scheduled(self::CRON_HOOK);
        if (is_int($next) && $next > 0 && $next <= ($when + 1)) {
            return;
        }
        wp_schedule_single_event($when, self::CRON_HOOK);
    }

    public static function clear_worker_schedule(): void {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }
}
