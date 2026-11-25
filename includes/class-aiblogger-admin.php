<?php
defined('ABSPATH') || exit;

class AIBlogger_Admin {

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
    }

    public static function menu(): void {
        add_menu_page(
            'AIBlogger',
            'AIBlogger',
            'edit_posts',
            'aiblogger_generate',
            [__CLASS__, 'render_generate_page'],
            'dashicons-edit-page',
            80
        );

        add_submenu_page(
            'aiblogger_generate',
            'Generar',
            'Generar',
            'edit_posts',
            'aiblogger_generate',
            [__CLASS__, 'render_generate_page']
        );

        add_submenu_page(
            'aiblogger_generate',
            'Configuracion',
            'Configuracion',
            'manage_options',
            'aiblogger_settings',
            [__CLASS__, 'render_settings_page']
        );

        add_submenu_page(
            'aiblogger_generate',
            'Metricas',
            'Metricas',
            'edit_posts',
            'aiblogger_metrics',
            [__CLASS__, 'render_metrics_page']
        );
    }

    public static function register_settings(): void {
        register_setting('aiblogger_settings_group', AIBLOGGER_OPT_KEY, [
            'sanitize_callback' => ['AIBlogger_Utils', 'sanitize_options'],
        ]);
    }

    public static function enqueue($hook): void {
        $plugin_pages = [
            'toplevel_page_aiblogger_generate',
            'aiblogger_page_aiblogger_settings',
            'aiblogger_page_aiblogger_metrics',
        ];
        $is_plugin_page = in_array($hook, $plugin_pages, true);
        $is_post_editor = in_array($hook, ['post.php', 'post-new.php'], true);

        if (!$is_plugin_page && !$is_post_editor) return;

        if ($is_plugin_page) {
            wp_enqueue_style('aiblogger-admin', AIBLOGGER_URL . 'assets/admin.css', [], AIBLOGGER_VERSION);
        }

        wp_enqueue_script('aiblogger-admin', AIBLOGGER_URL . 'assets/admin.js', ['jquery'], AIBLOGGER_VERSION, true);
        wp_enqueue_media();

        $opts = AIBlogger_Utils::get_options();
        wp_localize_script('aiblogger-admin', 'AIBLOGGER', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(AIBLOGGER_NONCE_ACTION),
            'openNewTabDefault' => (int)($opts['open_editor_new_tab'] ?? 1),
        ]);
    }

    private static function view(string $name, array $vars = []): void {
        $path = AIBLOGGER_DIR . 'admin/views/' . $name . '.php';
        if (!file_exists($path)) {
            echo '<div class="wrap"><h1>AIBlogger</h1><p>Vista no encontrada: ' . esc_html($name) . '</p></div>';
            return;
        }
        extract($vars, EXTR_SKIP);
        include $path;
    }

    public static function render_settings_page(): void {
        if (!AIBlogger_Utils::can_manage()) {
            echo '<div class="wrap"><h1>AIBlogger</h1><p>No tienes permisos para modificar la configuracion.</p></div>';
            return;
        }

        $opts = AIBlogger_Utils::get_options();
        $text_models  = AIBlogger_Models::curated_text_models();
        $image_models = AIBlogger_Models::curated_image_models();

        self::view('settings-page', compact('opts', 'text_models', 'image_models'));
    }

    public static function render_generate_page(): void {
        if (!AIBlogger_Utils::can_generate()) {
            echo '<div class="wrap"><h1>AIBlogger</h1><p>No tienes permisos para acceder.</p></div>';
            return;
        }

        $opts = AIBlogger_Utils::get_options();
        $text_models  = AIBlogger_Models::curated_text_models();
        $image_models = AIBlogger_Models::curated_image_models();

        self::view('generate-page', compact('opts', 'text_models', 'image_models'));
    }

    public static function render_metrics_page(): void {
        if (!AIBlogger_Utils::can_generate()) {
            echo '<div class="wrap"><h1>AIBlogger</h1><p>No tienes permisos.</p></div>';
            return;
        }

        self::view('metrics-page', []);
    }

    public static function render_model_select(string $name, string $selected, array $models, string $id = ''): void {
        $idAttr = $id ? ' id="' . esc_attr($id) . '"' : '';
        echo '<select' . $idAttr . ' name="' . esc_attr($name) . '">';
        foreach ($models as $m) {
            $mid = (string)($m['id'] ?? '');
            $lbl = (string)($m['label'] ?? $mid);
            if (!$mid) continue;
            echo '<option value="' . esc_attr($mid) . '" ' . selected($selected, $mid, false) . '>' . esc_html($lbl . ' - ' . $mid) . '</option>';
        }
        echo '</select>';
    }

    public static function render_language_select(string $name, string $selected, string $id = ''): void {
        $idAttr = $id ? ' id="' . esc_attr($id) . '"' : '';
        $langs = [
            'es' => 'Espanol',
            'en' => 'English',
            'pt' => 'Portugues',
            'fr' => 'Frances',
            'it' => 'Italiano',
            'de' => 'Deutsch',
        ];
        echo '<select' . $idAttr . ' name="' . esc_attr($name) . '">';
        foreach ($langs as $code => $label) {
            echo '<option value="' . esc_attr($code) . '" ' . selected($selected, $code, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    public static function render_post_type_select(string $name, string $selected, string $id = ''): void {
        $idAttr = $id ? ' id="' . esc_attr($id) . '"' : '';
        $pts = get_post_types(['show_ui' => true], 'objects');

        $excluded = [
            'attachment','page','nav_menu_item','wp_block','wp_navigation','wp_template','wp_template_part',
            'customize_changeset','oembed_cache','user_request','wp_font_family','wp_font_face',
        ];

        echo '<select' . $idAttr . ' name="' . esc_attr($name) . '">';
        foreach ($pts as $pt) {
            $pt_name = (string)($pt->name ?? '');
            if ($pt_name === '') continue;
            if (in_array($pt_name, $excluded, true)) continue;
            if (!post_type_supports($pt_name, 'editor')) continue;

            echo '<option value="' . esc_attr($pt_name) . '" ' . selected($selected, $pt_name, false) . '>' . esc_html($pt->labels->singular_name . ' (' . $pt_name . ')') . '</option>';
        }
        echo '</select>';
    }

    public static function render_status_select(string $name, string $selected, string $id = ''): void {
        $idAttr = $id ? ' id="' . esc_attr($id) . '"' : '';
        $statuses = [
            'draft' => 'Borrador',
            'pending' => 'Pendiente',
            'publish' => 'Publicar',
            'private' => 'Privado',
        ];
        echo '<select' . $idAttr . ' name="' . esc_attr($name) . '">';
        foreach ($statuses as $k => $label) {
            echo '<option value="' . esc_attr($k) . '" ' . selected($selected, $k, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    public static function render_aspect_select(string $name, string $selected, string $id = ''): void {
        $idAttr = $id ? ' id="' . esc_attr($id) . '"' : '';
        $opts = AIBlogger_Utils::supported_aspect_ratios_any();
        echo '<select' . $idAttr . ' name="' . esc_attr($name) . '">';
        foreach ($opts as $ar) {
            echo '<option value="' . esc_attr($ar) . '" ' . selected($selected, $ar, false) . '>' . esc_html($ar) . '</option>';
        }
        echo '</select>';
    }

    public static function render_image_size_select(string $name, string $selected, string $id = ''): void {
        $idAttr = $id ? ' id="' . esc_attr($id) . '"' : '';
        $opts = AIBlogger_Utils::supported_image_sizes_any();
        echo '<select' . $idAttr . ' name="' . esc_attr($name) . '">';
        foreach ($opts as $sz) {
            echo '<option value="' . esc_attr($sz) . '" ' . selected($selected, $sz, false) . '>' . esc_html($sz) . '</option>';
        }
        echo '</select>';
    }

    public static function render_image_display_size_select(string $name, string $selected, string $id = ''): void {
        $idAttr = $id ? ' id="' . esc_attr($id) . '"' : '';
        $sizes = [
            'thumbnail' => 'thumbnail',
            'medium' => 'medium',
            'large' => 'large',
            'full' => 'full',
        ];
        echo '<select' . $idAttr . ' name="' . esc_attr($name) . '">';
        foreach ($sizes as $k => $label) {
            echo '<option value="' . esc_attr($k) . '" ' . selected($selected, $k, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }
}
