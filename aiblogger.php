<?php
/**
 * Plugin Name: AIBlogger
 * Plugin URI:  https://gorvet.com
 * Description: Genera artículos optimizados para buscadores con texto e imágenes de Gemini en WordPress.
 * Version:     1.0.6
 * Author: Juank de Gorvet
 * Author URI: https://api.whatsapp.com/send/?phone=5353779424
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: aiblogger
 * Domain Path: /languages
 * Requires at least: 6.5
 * Tested up to: 6.8
 * Requires PHP: 8.1
 * Update URI: https://repo.gorvet.com/updates/aiblogger/info.json
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AIBLOGGER_VERSION', get_file_data(__FILE__, ['version' => 'Version'])['version']);
define('AIBLOGGER_DIR', plugin_dir_path(__FILE__));
define('AIBLOGGER_URL', plugin_dir_url(__FILE__));
define('AIBLOGGER_SLUG', plugin_basename(__FILE__));
define('AIBLOGGER_UPDATE_URL', 'https://repo.gorvet.com/updates/aiblogger/info.json');

define('AIBLOGGER_OPT_KEY', 'aiblogger_options');
define('AIBLOGGER_MODELS_CACHE_KEY', 'aiblogger_models_cache');
define('AIBLOGGER_NONCE_ACTION', 'aiblogger_nonce');

require_once AIBLOGGER_DIR . 'includes/class-aiblogger-loader.php';

register_activation_hook(__FILE__, ['AIBlogger_Loader', 'activate']);
register_deactivation_hook(__FILE__, ['AIBlogger_Loader', 'deactivate']);

add_action('plugins_loaded', ['AIBlogger_Loader', 'init']);
