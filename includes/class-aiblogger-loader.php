<?php
defined('ABSPATH') || exit;

class AIBlogger_Loader {

    public static function init() {
        require_once AIBLOGGER_DIR . 'includes/class-aiblogger-utils.php';
        require_once AIBLOGGER_DIR . 'includes/class-aiblogger-prompts.php';
        require_once AIBLOGGER_DIR . 'includes/class-aiblogger-link-suggester.php';
        require_once AIBLOGGER_DIR . 'includes/class-aiblogger-job-queue.php';
        require_once AIBLOGGER_DIR . 'includes/class-aiblogger-install.php';
        require_once AIBLOGGER_DIR . 'includes/class-aiblogger-update.php';
        require_once AIBLOGGER_DIR . 'includes/class-aiblogger-models.php';
        require_once AIBLOGGER_DIR . 'includes/class-aiblogger-reference-fetcher.php';
        require_once AIBLOGGER_DIR . 'includes/class-aiblogger-gemini-client.php';
        require_once AIBLOGGER_DIR . 'includes/class-aiblogger-content-builder.php';
        require_once AIBLOGGER_DIR . 'includes/class-aiblogger-image-handler.php';
        require_once AIBLOGGER_DIR . 'includes/class-aiblogger-post-publisher.php';
        require_once AIBLOGGER_DIR . 'includes/class-aiblogger-ajax-controller.php';
        require_once AIBLOGGER_DIR . 'includes/class-aiblogger-ajax.php';

        AIBlogger_Install::init();
        AIBlogger_Update::init();
        AIBlogger_Image_Handler::init();
        AIBlogger_Job_Queue::init();
        AIBlogger_Ajax::init();

        if (is_admin()) {
            require_once AIBLOGGER_DIR . 'includes/class-aiblogger-admin.php';
            require_once AIBLOGGER_DIR . 'includes/class-aiblogger-metabox.php';
            require_once AIBLOGGER_DIR . 'includes/class-aiblogger-editor-ajax.php';

            AIBlogger_Editor_Ajax::init();
            AIBlogger_Admin::init();
            AIBlogger_Metabox::init();
        }
    }

    public static function activate(): void {
        require_once AIBLOGGER_DIR . 'includes/class-aiblogger-utils.php';
        require_once AIBLOGGER_DIR . 'includes/class-aiblogger-job-queue.php';
        require_once AIBLOGGER_DIR . 'includes/class-aiblogger-install.php';

        AIBlogger_Install::activate();
    }

    public static function deactivate(): void {
        require_once AIBLOGGER_DIR . 'includes/class-aiblogger-job-queue.php';
        require_once AIBLOGGER_DIR . 'includes/class-aiblogger-install.php';

        AIBlogger_Install::deactivate();
    }
}
