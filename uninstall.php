<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('aiblogger_options');
delete_option('aiblogger_models_cache');
delete_option('aiblogger_version');
delete_option('aiblogger_usage_log');
delete_option('aiblogger_usage_backfill_done');

if (isset($GLOBALS['wpdb'])) {
    $table = $GLOBALS['wpdb']->prefix . 'aiblogger_usage_events';
    $GLOBALS['wpdb']->query("DROP TABLE IF EXISTS {$table}");

    $jobs_table = $GLOBALS['wpdb']->prefix . 'aiblogger_jobs';
    $GLOBALS['wpdb']->query("DROP TABLE IF EXISTS {$jobs_table}");
}

if (function_exists('wp_clear_scheduled_hook')) {
    wp_clear_scheduled_hook('aiblogger_process_queue');
}
