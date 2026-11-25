<?php
defined('ABSPATH') || exit;

/**
 * Instalacion / migraciones internas.
 */
class AIBlogger_Install {

    public static function init(): void {
        add_action('admin_init', [__CLASS__, 'maybe_migrate']);
    }

    public static function activate(): void {
        if (!class_exists('AIBlogger_Utils') || !class_exists('AIBlogger_Job_Queue')) return;

        AIBlogger_Utils::ensure_usage_table();
        AIBlogger_Job_Queue::ensure_table();

        if (!get_option(AIBLOGGER_OPT_KEY)) {
            update_option(AIBLOGGER_OPT_KEY, AIBlogger_Utils::defaults());
        }
        update_option('aiblogger_version', AIBLOGGER_VERSION);
    }

    public static function deactivate(): void {
        if (class_exists('AIBlogger_Job_Queue')) {
            AIBlogger_Job_Queue::clear_worker_schedule();
        }
    }

    public static function maybe_migrate(): void {
        if (!class_exists('AIBlogger_Utils') || !class_exists('AIBlogger_Job_Queue')) return;
        if (!AIBlogger_Utils::can_manage()) return;

        // Always ensure metrics table exists and migrate legacy option logs once.
        AIBlogger_Utils::ensure_usage_table();
        AIBlogger_Job_Queue::ensure_table();
        AIBlogger_Utils::migrate_usage_log_option_to_table();

        if (!get_option('aiblogger_usage_backfill_done')) {
            AIBlogger_Utils::backfill_post_usage_events(1500);
            update_option('aiblogger_usage_backfill_done', 1, false);
        }

        $stored = get_option('aiblogger_version');
        if (!$stored) {
            update_option('aiblogger_version', AIBLOGGER_VERSION);
            if (!get_option(AIBLOGGER_OPT_KEY)) {
                update_option(AIBLOGGER_OPT_KEY, AIBlogger_Utils::defaults());
            }
            return;
        }

        if (version_compare($stored, AIBLOGGER_VERSION, '>=')) return;

        // Soft migration: ensure all default keys exist.
        AIBlogger_Utils::update_options(AIBlogger_Utils::get_options());

        update_option('aiblogger_version', AIBLOGGER_VERSION);
    }
}
