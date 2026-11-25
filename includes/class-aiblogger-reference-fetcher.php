<?php
defined('ABSPATH') || exit;

class AIBlogger_Reference_Fetcher {
    private static function cache_ttl_seconds(): int {
        $ttl = (int)apply_filters('aiblogger_reference_cache_ttl', 0);
        return max(0, min(300, $ttl));
    }

    private static function cache_key_for_url(string $url): string {
        return 'aib_ref_' . md5(strtolower(trim($url)));
    }

    public static function fetch_urls(array $urls): array {
        $out = [];
        foreach ($urls as $u) {
            $out[] = self::fetch_one($u);
        }
        return $out;
    }

    private static function fetch_one(string $url): array {
        $ttl = self::cache_ttl_seconds();
        if ($ttl > 0) {
            $cached = get_transient(self::cache_key_for_url($url));
            if (is_array($cached) && isset($cached['url'], $cached['ok'])) {
                return $cached;
            }
        }

        $res = wp_safe_remote_get($url, [
            'timeout' => 12,
            'redirection' => 3,
            'headers' => [
                'User-Agent' => 'AIBlogger/' . AIBLOGGER_VERSION,
            ],
        ]);

        if (is_wp_error($res)) {
            $out = [
                'url' => $url,
                'ok' => false,
                'title' => '',
                'text' => '',
                'error' => $res->get_error_message(),
            ];
            if ($ttl > 0) set_transient(self::cache_key_for_url($url), $out, $ttl);
            return $out;
        }

        $code = (int) wp_remote_retrieve_response_code($res);
        $html = (string) wp_remote_retrieve_body($res);
        if ($code < 200 || $code >= 300 || !$html) {
            $out = [
                'url' => $url,
                'ok' => false,
                'title' => '',
                'text' => '',
                'error' => 'HTTP ' . $code,
            ];
            if ($ttl > 0) set_transient(self::cache_key_for_url($url), $out, $ttl);
            return $out;
        }

        $title = self::extract_title($html);
        $text  = self::extract_text($html);

        $out = [
            'url' => $url,
            'ok' => true,
            'title' => $title,
            'text' => AIBlogger_Utils::safe_excerpt($text, 1200),
            'error' => '',
        ];
        if ($ttl > 0) set_transient(self::cache_key_for_url($url), $out, $ttl);
        return $out;
    }

    private static function extract_title(string $html): string {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            return trim(wp_strip_all_tags($m[1]));
        }
        return '';
    }

    private static function extract_text(string $html): string {
        // Elimina scripts/styles.
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html);
        $text = wp_strip_all_tags($html);
        // Limpieza básica.
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /**
     * Intenta armar un contexto del sitio (home + about).
     * Solo hace requests al propio dominio.
     */
    public static function build_site_context(): string {
        $home = home_url('/');
        $site_name = get_bloginfo('name');
        $tagline = get_bloginfo('description');

        $parts = [];
        $parts[] = "Nombre del sitio: {$site_name}.";
        if ($tagline) $parts[] = "Descripción corta: {$tagline}.";

        $home_info = self::fetch_one($home);
        if (!empty($home_info['ok']) && !empty($home_info['text'])) {
            $parts[] = "Contenido del home (resumen): " . AIBlogger_Utils::safe_excerpt($home_info['text'], 650);
        }

        $about_slugs = [
            'about', 'about-us', 'acerca-de', 'quienes-somos', 'sobre-nosotros', 'sobre', 'empresa'
        ];

        foreach ($about_slugs as $slug) {
            $u = home_url('/' . $slug . '/');
            $info = self::fetch_one($u);
            if (!empty($info['ok']) && !empty($info['text'])) {
                $parts[] = "Página “{$slug}” (resumen): " . AIBlogger_Utils::safe_excerpt($info['text'], 650);
                break;
            }
        }

        return trim(implode("\n\n", $parts));
    }

    /**
     * Devuelve fuentes crudas (home + about) para que la IA genere un "site_context".
     * Retorna string o WP_Error.
     */
    public static function build_site_context_raw_sources() {
        $home = home_url('/');
        $site_name = get_bloginfo('name');
        $tagline = get_bloginfo('description');

        $chunks = [];
        $chunks[] = "SITE_NAME: {$site_name}";
        if ($tagline) $chunks[] = "SITE_TAGLINE: {$tagline}";

        $home_info = self::fetch_one($home);
        if (empty($home_info['ok'])) {
            return new WP_Error('aiblogger_site_context', 'No se pudo leer el home del sitio.');
        }
        $chunks[] = "HOME_URL: {$home}";
        $chunks[] = "HOME_TITLE: " . ($home_info['title'] ?? '');
        $chunks[] = "HOME_TEXT: " . AIBlogger_Utils::safe_excerpt((string)($home_info['text'] ?? ''), 1800);

        $about_slugs = ['about','about-us','acerca-de','quienes-somos','sobre-nosotros','sobre','empresa'];
        foreach ($about_slugs as $slug) {
            $u = home_url('/' . $slug . '/');
            $info = self::fetch_one($u);
            if (!empty($info['ok']) && !empty($info['text'])) {
                $chunks[] = "ABOUT_URL: {$u}";
                $chunks[] = "ABOUT_TITLE: " . ($info['title'] ?? '');
                $chunks[] = "ABOUT_TEXT: " . AIBlogger_Utils::safe_excerpt((string)($info['text'] ?? ''), 1800);
                break;
            }
        }

        return implode("\n", $chunks);
    }
}
