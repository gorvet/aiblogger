<?php
defined('ABSPATH') || exit;

class AIBlogger_Image_Handler {

    public static function init(): void {
        add_filter('image_size_names_choose', [__CLASS__, 'add_editor_size']);
        add_filter('wp_calculate_image_srcset', [__CLASS__, 'filter_srcset_for_optimized'], 10, 5);
    }

    public static function add_editor_size(array $sizes): array {
        $sizes['aiblogger_opt'] = 'AIBlogger optimizada';
        return $sizes;
    }

    public static function filter_srcset_for_optimized($sources, $size_array, $image_src, $image_meta, $attachment_id) {
        if (is_string($image_src) && preg_match('/-opt\.webp(?:\?|$)/i', $image_src)) {
            return false;
        }
        return $sources;
    }

    /**
     * Save image bytes in Media Library.
     *
     * Rule:
     * - Keep original file.
     * - Generate optimized variant (-opt) and store as custom size.
     */
    public static function save_image_bytes(string $bytes, string $mime, string $title = '') {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $ext = 'png';
        if ($mime === 'image/jpeg') $ext = 'jpg';
        if ($mime === 'image/webp') $ext = 'webp';

        $filename = 'aiblogger-' . gmdate('Ymd-His') . '-' . wp_generate_password(6, false) . '.' . $ext;

        $upload = wp_upload_bits($filename, null, $bytes);
        if (!empty($upload['error'])) {
            return new WP_Error('aiblogger_upload_error', $upload['error']);
        }

        $orig_file = (string)($upload['file'] ?? '');
        if (!$orig_file || !file_exists($orig_file)) {
            return new WP_Error('aiblogger_upload_error', 'No se pudo crear el archivo en uploads.');
        }

        $attach_file = $orig_file;

        $opts = class_exists('AIBlogger_Utils') ? AIBlogger_Utils::get_options() : [];
        $optimized_info = null;

        if (!empty($opts['optimize_images'])) {
            $optimized = self::optimize_image($orig_file);
            if (!is_wp_error($optimized) && is_array($optimized) && !empty($optimized['path']) && file_exists((string)$optimized['path'])) {
                $optimized_info = $optimized;
            }
        }

        $filetype = wp_check_filetype($attach_file, null);
        $attachment = [
            'post_mime_type' => $filetype['type'] ?: $mime,
            'post_title'     => $title ?: sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];

        $attach_id = wp_insert_attachment($attachment, $attach_file);
        if (is_wp_error($attach_id)) return $attach_id;

        update_post_meta($attach_id, '_aiblogger_original_file', $orig_file);
        if (is_array($optimized_info) && !empty($optimized_info['path'])) {
            update_post_meta($attach_id, '_aiblogger_optimized_file', (string)$optimized_info['path']);
            update_post_meta($attach_id, '_aiblogger_optimized_size_bytes', (int)($optimized_info['size_bytes'] ?? 0));
        }

        $meta = wp_generate_attachment_metadata($attach_id, $attach_file);

        if (is_array($meta) && is_array($optimized_info) && !empty($optimized_info['path']) && file_exists((string)$optimized_info['path'])) {
            if (!isset($meta['sizes']) || !is_array($meta['sizes'])) $meta['sizes'] = [];

            $opt_path = (string)$optimized_info['path'];
            $opt_file = basename($opt_path);
            $opt_w = (int)($optimized_info['width'] ?? 0);
            $opt_h = (int)($optimized_info['height'] ?? 0);
            $opt_mime = (string)($optimized_info['mime'] ?? 'image/webp');
            $opt_bytes = (int)($optimized_info['size_bytes'] ?? 0);

            if ($opt_w <= 0 || $opt_h <= 0) {
                $opt_w = (int)($meta['width'] ?? 0);
                $opt_h = (int)($meta['height'] ?? 0);
            }

            $meta['sizes']['aiblogger_opt'] = [
                'file' => $opt_file,
                'width' => $opt_w,
                'height' => $opt_h,
                'mime-type' => $opt_mime,
                'filesize' => $opt_bytes,
            ];
        }

        wp_update_attachment_metadata($attach_id, $meta);

        return $attach_id;
    }

    private static function optimize_limits(): array {
        $target_kb = (int)apply_filters('aiblogger_opt_target_kb', 450);
        $max_kb = (int)apply_filters('aiblogger_opt_max_kb', 800);
        $max_width = (int)apply_filters('aiblogger_opt_max_width', 2560);
        $min_width = (int)apply_filters('aiblogger_opt_min_width', 1024);

        $target_kb = max(200, min($target_kb, 800));
        $max_kb = max($target_kb, min($max_kb, 1400));
        $max_width = max(800, min($max_width, 4000));
        $min_width = max(640, min($min_width, $max_width));

        return [
            'target_bytes' => $target_kb * 1024,
            'max_bytes' => $max_kb * 1024,
            'max_width' => $max_width,
            'min_width' => $min_width,
        ];
    }

    private static function optimize_image(string $file) {
        $limits = self::optimize_limits();

        $dims = @getimagesize($file);
        $orig_w = (int)($dims[0] ?? 0);

        $pi = pathinfo($file);
        $dir = (string)($pi['dirname'] ?? '');
        $name = (string)($pi['filename'] ?? 'image');
        $ext = strtolower((string)($pi['extension'] ?? 'jpg'));

        $width_steps = self::build_width_steps($orig_w, $limits['max_width'], $limits['min_width']);
        $quality_steps = [82, 76, 70, 64, 58, 52, 46, 40, 36];

        $tmp_files = [];
        $best_good = null;
        $best_good_diff = PHP_INT_MAX;
        $best_over = null;
        $best_over_size = PHP_INT_MAX;

        foreach ($width_steps as $target_w) {
            foreach ($quality_steps as $q) {
                $trial = $dir . '/' . $name . '-opt-w' . (int)$target_w . '-q' . (int)$q . '.webp';
                $tmp_files[] = $trial;

                $encoded = self::encode_variant($file, $trial, $target_w, $q, 'image/webp');
                if (is_wp_error($encoded)) continue;

                $bytes = @filesize($trial);
                if (!is_int($bytes) || $bytes <= 0) continue;

                $candidate = [
                    'path' => $trial,
                    'width' => (int)($encoded['width'] ?? 0),
                    'height' => (int)($encoded['height'] ?? 0),
                    'mime' => 'image/webp',
                    'size_bytes' => $bytes,
                ];

                if ($bytes <= $limits['max_bytes']) {
                    $diff = abs($bytes - $limits['target_bytes']);
                    if ($diff < $best_good_diff) {
                        $best_good_diff = $diff;
                        $best_good = $candidate;
                    }
                    if ($bytes <= $limits['target_bytes']) {
                        break 2;
                    }
                } else {
                    if ($bytes < $best_over_size) {
                        $best_over_size = $bytes;
                        $best_over = $candidate;
                    }
                }
            }
        }

        $best = $best_good ?: $best_over;

        // Fallback to same extension if webp path failed.
        if (!$best) {
            $fallback = $dir . '/' . $name . '-opt.' . ($ext ?: 'jpg');
            $encoded = self::encode_variant($file, $fallback, $width_steps[0] ?? 0, 76, null);
            if (is_wp_error($encoded)) return $encoded;
            $bytes = @filesize($fallback);
            if (!is_int($bytes) || $bytes <= 0) {
                return new WP_Error('aiblogger_optimize_error', 'No se pudo crear la imagen optimizada.');
            }
            return [
                'path' => $fallback,
                'width' => (int)($encoded['width'] ?? 0),
                'height' => (int)($encoded['height'] ?? 0),
                'mime' => (string)($encoded['mime'] ?? ''),
                'size_bytes' => $bytes,
            ];
        }

        $final = $dir . '/' . $name . '-opt.webp';
        if (file_exists($final)) {
            @unlink($final);
        }

        if (!@rename((string)$best['path'], $final)) {
            if (!@copy((string)$best['path'], $final)) {
                self::cleanup_files($tmp_files);
                return new WP_Error('aiblogger_optimize_error', 'No se pudo guardar la imagen optimizada final.');
            }
        }

        $best['path'] = $final;
        $best['size_bytes'] = (int)(@filesize($final) ?: ($best['size_bytes'] ?? 0));

        self::cleanup_files($tmp_files, [$final]);

        return $best;
    }

    private static function build_width_steps(int $orig_w, int $max_w, int $min_w): array {
        if ($orig_w <= 0) return [0];

        $start = min($orig_w, $max_w);
        $steps = [$start];

        $cursor = $start;
        while ($cursor > $min_w) {
            $cursor = (int)floor($cursor * 0.9);
            if ($cursor < $min_w) $cursor = $min_w;
            $steps[] = $cursor;
            if ($cursor === $min_w) break;
        }

        $steps = array_values(array_unique(array_filter($steps, function($n){ return (int)$n > 0; })));
        if (!$steps) return [0];
        return $steps;
    }

    private static function encode_variant(string $source, string $dest, int $target_w, int $quality, ?string $mime = 'image/webp') {
        $editor = wp_get_image_editor($source);
        if (is_wp_error($editor)) return $editor;

        $size = $editor->get_size();
        $src_w = (int)($size['width'] ?? 0);

        if ($target_w > 0 && $src_w > 0 && $target_w < $src_w) {
            $resized = $editor->resize($target_w, null, false);
            if (is_wp_error($resized)) return $resized;
        }

        $editor->set_quality($quality);

        $saved = $mime ? $editor->save($dest, $mime) : $editor->save($dest);
        if (is_wp_error($saved)) return $saved;

        return [
            'path' => (string)($saved['path'] ?? $dest),
            'width' => (int)($saved['width'] ?? 0),
            'height' => (int)($saved['height'] ?? 0),
            'mime' => (string)($saved['mime-type'] ?? ($mime ?: '')),
        ];
    }

    private static function cleanup_files(array $paths, array $keep = []): void {
        $keep_map = [];
        foreach ($keep as $k) {
            $keep_map[(string)$k] = true;
        }
        foreach ($paths as $p) {
            $p = (string)$p;
            if ($p === '' || !file_exists($p)) continue;
            if (isset($keep_map[$p])) continue;
            @unlink($p);
        }
    }

    public static function get_image_url(int $attachment_id, string $size = 'large'): ?string {
        $src = wp_get_attachment_image_src($attachment_id, $size);
        if ($src && !empty($src[0])) return $src[0];

        if ($size === 'aiblogger_opt') {
            $opt = self::get_optimized_variant($attachment_id);
            if (is_array($opt) && !empty($opt['url'])) {
                return (string)$opt['url'];
            }
        }

        return null;
    }

    private static function get_optimized_variant(int $attachment_id): ?array {
        $meta = wp_get_attachment_metadata($attachment_id);
        if (!is_array($meta)) return null;

        $opt = $meta['sizes']['aiblogger_opt'] ?? null;
        if (!is_array($opt) || empty($opt['file'])) return null;

        $orig_url = wp_get_attachment_url($attachment_id);
        if (!$orig_url) return null;

        $base_url = trailingslashit(dirname($orig_url));
        $url = $base_url . ltrim((string)$opt['file'], '/');

        return [
            'url' => $url,
            'width' => (int)($opt['width'] ?? 0),
            'height' => (int)($opt['height'] ?? 0),
        ];
    }

    public static function get_img_html(int $attachment_id, string $size, string $alt = ''): string {
        $size_slug = sanitize_key($size);
        $figure_class = 'aiblogger-image size-' . ($size_slug ?: 'large');

        // In article body always prefer optimized variant when available.
        $opt = self::get_optimized_variant($attachment_id);
        if (is_array($opt) && !empty($opt['url'])) {
            $w = (int)($opt['width'] ?? 0);
            $h = (int)($opt['height'] ?? 0);
            $img = '<img src="' . esc_url((string)$opt['url']) . '" alt="' . esc_attr($alt) . '" loading="lazy" decoding="async" class="wp-image-' . (int)$attachment_id . ' aiblogger-opt"';
            if ($w > 0) $img .= ' width="' . $w . '"';
            if ($h > 0) $img .= ' height="' . $h . '"';
            $img .= ' />';
            return '<figure class="' . esc_attr($figure_class) . '">' . $img . '</figure>';
        }

        $attrs = [
            'alt' => $alt,
            'loading' => 'lazy',
            'decoding' => 'async',
        ];

        $img = wp_get_attachment_image($attachment_id, $size_slug ?: 'large', false, $attrs);
        if (!$img) {
            $url = self::get_image_url($attachment_id, $size_slug ?: 'large') ?: self::get_image_url($attachment_id, 'full');
            if ($url) {
                $img = '<img src="' . esc_url($url) . '" alt="' . esc_attr($alt) . '" loading="lazy" decoding="async" />';
            } else {
                $img = '';
            }
        }

        return '<figure class="' . esc_attr($figure_class) . '">' . $img . '</figure>';
    }
}
