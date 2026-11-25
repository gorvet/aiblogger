<?php
defined('ABSPATH') || exit;

class AIBlogger_Update {

    private const SLUG = 'aiblogger';

    public static function init(): void {
        add_filter('update_plugins_repo.gorvet.com', [__CLASS__, 'check_for_updates'], 10, 3);
        add_filter('plugins_api', [__CLASS__, 'plugin_information'], 10, 3);
    }

    public static function check_for_updates($update, array $plugin_data, string $plugin_file) {
        if (AIBLOGGER_SLUG !== $plugin_file || AIBLOGGER_UPDATE_URL !== ($plugin_data['UpdateURI'] ?? '')) {
            return $update;
        }

        $url = self::remote_url();
        if ($url === '') {
            return $update;
        }

        $info = self::fetch_manifest($url);

        return [
            'slug'           => self::SLUG,
            'version'        => $info->version ?? $plugin_data['Version'],
            'url'            => $info->homepage ?? $plugin_data['PluginURI'],
            'package'        => $info->download_url ?? '',
            'requires'       => $info->requires ?? $plugin_data['RequiresWP'],
            'requires_php'   => $info->requires_php ?? $plugin_data['RequiresPHP'],
            'tested'         => $info->tested ?? '6.8',
            'icons'          => isset($info->icon_url) ? ['default' => $info->icon_url] : [],
            'upgrade_notice' => $info->upgrade_notice ?? '',
        ];
    }

    public static function plugin_information($result, string $action, $args) {
        if ('plugin_information' !== $action || self::SLUG !== ($args->slug ?? '')) {
            return $result;
        }

        $url = self::remote_url();
        if ($url === '') {
            return new WP_Error('aiblogger_update', __('No hay una URL de actualización configurada.', 'aiblogger'));
        }

        $info = self::fetch_manifest($url);
        if (!$info) {
            return new WP_Error('aiblogger_update', __('No se pudo obtener información del plugin.', 'aiblogger'));
        }

        return (object) [
            'name'          => $info->name ?? 'AIBlogger',
            'slug'          => self::SLUG,
            'version'       => $info->version,
            'author'        => $info->author ?? 'Juank de Gorvet',
            'requires'      => $info->requires ?? '6.5',
            'requires_php'  => $info->requires_php ?? '8.1',
            'tested'        => $info->tested ?? '6.8',
            'last_updated'  => $info->last_updated ?? '',
            'homepage'      => $info->homepage ?? 'https://gorvet.com',
            'download_link' => $info->download_url,
            'sections'      => array_filter((array) ($info->sections ?? []), 'is_string'),
            'banners'       => array_filter((array) ($info->banners ?? []), 'is_string'),
        ];
    }

    private static function remote_url(): string {
        $url = apply_filters('aiblogger_update_remote_url', AIBLOGGER_UPDATE_URL);
        return is_string($url) ? trim($url) : '';
    }

    private static function fetch_manifest(string $url): ?object {
        static $manifests = [];
        if (array_key_exists($url, $manifests)) {
            return $manifests[$url];
        }

        $manifests[$url] = null;
        $response = wp_safe_remote_get($url, ['timeout' => 5]);
        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response));
        if (!is_object($data) ||
            self::SLUG !== ($data->slug ?? self::SLUG) ||
            !is_string($data->version ?? null) ||
            !preg_match('/^[0-9][0-9A-Za-z.+_-]{0,63}$/D', $data->version) ||
            !is_string($data->download_url ?? null) ||
            !filter_var($data->download_url, FILTER_VALIDATE_URL) ||
            'https' !== wp_parse_url($data->download_url, PHP_URL_SCHEME)
        ) {
            return null;
        }

        foreach (['name', 'author', 'homepage', 'requires', 'requires_php', 'tested', 'upgrade_notice', 'last_updated', 'icon_url'] as $field) {
            if (isset($data->$field) && (!is_string($data->$field) || '' === $data->$field)) {
                unset($data->$field);
            }
        }

        $manifests[$url] = $data;
        return $data;
    }
}
