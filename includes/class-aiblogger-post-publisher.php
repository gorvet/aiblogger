<?php
defined('ABSPATH') || exit;

class AIBlogger_Post_Publisher {

    /**
     * Crea el post y guarda SEO + mÃƒÆ’Ã‚Â©tricas.
     *
     * $metrics = [
     *   'text_model' => string,
     *   'image_model' => string,
     *   'generated_at' => int,
     *   'totals' => [...],
     *   'calls' => [...]
     * ]
     */
    public static function create_post(array $gen, array $params, int $featured_attachment_id = 0, array $internal_images = [], array $metrics = []) {
        require_once ABSPATH . 'wp-admin/includes/post.php';

        $title = sanitize_text_field($gen['title'] ?? '');
        $excerpt = sanitize_text_field($gen['excerpt'] ?? '');
        $slug = sanitize_title($gen['slug'] ?? '');

        $content_html = (string)($gen['content_html'] ?? '');
        $content_html = self::sanitize_content($content_html);

        if (!empty($internal_images)) {
            $content_html = self::inject_internal_images($content_html, $internal_images, (string)($params['internal_image_display_size'] ?? 'large'));
        }

        $post_content = self::maybe_convert_html_to_blocks($content_html);

        $postarr = [
            'post_title'   => $title ?: 'Borrador generado',
            'post_content' => $post_content,
            'post_excerpt' => $excerpt,
            'post_status'  => sanitize_key($params['post_status'] ?? 'draft'),
            'post_type'    => sanitize_key($params['post_type'] ?? 'post'),
        ];

        if ($slug) $postarr['post_name'] = $slug;

        $post_id = wp_insert_post($postarr, true);
        if (is_wp_error($post_id)) return $post_id;

        $post_type = (string)($postarr['post_type'] ?? 'post');

        // TaxonomÃƒÆ’Ã‚Â­as: categorÃƒÆ’Ã‚Â­as + tags
        self::assign_terms($post_id, $post_type, $gen, $params);

        // Meta propia
        if (!empty($gen['seo_meta_description'])) {
            update_post_meta($post_id, '_aiblogger_meta_description', sanitize_text_field($gen['seo_meta_description']));
        }
        if (!empty($gen['focus_keyword'])) {
            update_post_meta($post_id, '_aiblogger_focus_keyword', sanitize_text_field($gen['focus_keyword']));
        }

        // Integraciones SEO: Yoast + Rank Math
        if (!empty($params['seo_integrations'])) {
            $seo_title = $title ?: '';
            $seo_desc  = !empty($gen['seo_meta_description']) ? sanitize_text_field($gen['seo_meta_description']) : '';
            $focus_kw  = !empty($gen['focus_keyword']) ? sanitize_text_field($gen['focus_keyword']) : '';

            if ($seo_title) {
                update_post_meta($post_id, '_yoast_wpseo_title', $seo_title);
                update_post_meta($post_id, 'rank_math_title', $seo_title);
            }
            if ($seo_desc) {
                update_post_meta($post_id, '_yoast_wpseo_metadesc', $seo_desc);
                update_post_meta($post_id, 'rank_math_description', $seo_desc);
            }
            if ($focus_kw) {
                update_post_meta($post_id, '_yoast_wpseo_focuskw', $focus_kw);
                update_post_meta($post_id, 'rank_math_focus_keyword', $focus_kw);
            }
        }

        // Guardar referencias
        if (!empty($params['reference_urls']) && is_array($params['reference_urls'])) {
            update_post_meta($post_id, '_aiblogger_reference_urls', array_values($params['reference_urls']));
        }

        // Warnings
        if (!empty($gen['warnings']) && is_array($gen['warnings'])) {
            $w = array_slice(array_map('sanitize_text_field', $gen['warnings']), 0, 20);
            update_post_meta($post_id, '_aiblogger_warnings', $w);
        }

        // Imagen destacada
        if ($featured_attachment_id > 0) {
            set_post_thumbnail($post_id, $featured_attachment_id);
            if (!empty($gen['featured_image_alt'])) {
                update_post_meta($featured_attachment_id, '_wp_attachment_image_alt', sanitize_text_field($gen['featured_image_alt']));
            }
        }

        // Marca
        update_post_meta($post_id, '_aiblogger_generated_at', time());
        self::store_generation_meta($post_id, $params);

        // MÃƒÆ’Ã‚Â©tricas por post
        if (!empty($params['enable_metrics']) && is_array($metrics) && !empty($metrics['totals'])) {
            $tot = is_array($metrics['totals']) ? $metrics['totals'] : [];

            $breakdown = isset($metrics['breakdown']) && is_array($metrics['breakdown']) ? $metrics['breakdown'] : [];
            $bt = isset($breakdown['text']) && is_array($breakdown['text']) ? $breakdown['text'] : [];
            $bi = isset($breakdown['images']) && is_array($breakdown['images']) ? $breakdown['images'] : [];
            $bx = isset($breakdown['extra']) && is_array($breakdown['extra']) ? $breakdown['extra'] : [];
            $bc = isset($breakdown['counts']) && is_array($breakdown['counts']) ? $breakdown['counts'] : [];

            $safe_calls = [];
            if (!empty($metrics['calls']) && is_array($metrics['calls'])) {
                foreach (array_slice($metrics['calls'], 0, 20) as $c) {
                    if (!is_array($c)) continue;
                    $u = isset($c['usage']) && is_array($c['usage']) ? $c['usage'] : [];
                    $safe_calls[] = [
                        'type' => sanitize_text_field((string)($c['type'] ?? '')),
                        'label' => sanitize_text_field((string)($c['label'] ?? '')),
                        'model' => sanitize_text_field((string)($c['model'] ?? '')),
                        'usage' => [
                            'prompt_tokens' => (int)($u['prompt_tokens'] ?? 0),
                            'output_tokens' => (int)($u['output_tokens'] ?? 0),
                            'total_tokens' => (int)($u['total_tokens'] ?? 0),
                            'tool_tokens' => (int)($u['tool_tokens'] ?? 0),
                            'thought_tokens' => (int)($u['thought_tokens'] ?? 0),
                            'cached_tokens' => (int)($u['cached_tokens'] ?? 0),
                        ],
                    ];
                }
            }

            $metrics_store = [
                'text_model' => sanitize_text_field((string)($metrics['text_model'] ?? '')),
                'image_model' => sanitize_text_field((string)($metrics['image_model'] ?? '')),

                // Totales globales
                'prompt_tokens' => (int)($tot['prompt_tokens'] ?? 0),
                'output_tokens' => (int)($tot['output_tokens'] ?? 0),
                'tool_tokens' => (int)($tot['tool_tokens'] ?? 0),
                'thought_tokens' => (int)($tot['thought_tokens'] ?? 0),
                'cached_tokens' => (int)($tot['cached_tokens'] ?? 0),
                'total_tokens'  => (int)($tot['total_tokens'] ?? 0),

                // Totales por tipo (texto / imÃƒÆ’Ã‚Â¡genes / extra)
                'text_prompt_tokens' => (int)($bt['prompt_tokens'] ?? 0),
                'text_output_tokens' => (int)($bt['output_tokens'] ?? 0),
                'text_total_tokens'  => (int)($bt['total_tokens'] ?? 0),

                'image_prompt_tokens' => (int)($bi['prompt_tokens'] ?? 0),
                'image_output_tokens' => (int)($bi['output_tokens'] ?? 0),
                'image_total_tokens'  => (int)($bi['total_tokens'] ?? 0),

                'extra_prompt_tokens' => (int)($bx['prompt_tokens'] ?? 0),
                'extra_output_tokens' => (int)($bx['output_tokens'] ?? 0),
                'extra_total_tokens'  => (int)($bx['total_tokens'] ?? 0),

                // Cantidad de llamadas (ÃƒÆ’Ã‚Âºtil para validar si contÃƒÆ’Ã‚Â³ 1 o varias imÃƒÆ’Ã‚Â¡genes)
                'text_calls'  => (int)($bc['text_calls'] ?? 0),
                'image_calls' => (int)($bc['image_calls'] ?? 0),
                'extra_calls' => (int)($bc['extra_calls'] ?? 0),

                'generated_at' => (int)($metrics['generated_at'] ?? time()),
                'calls' => $safe_calls,
            ];

            update_post_meta($post_id, '_aiblogger_metrics', $metrics_store);
            AIBlogger_Utils::log_usage_event([
                'label' => 'Post #' . (int)$post_id . ' generado',
                'type' => 'post',
                'model' => trim((string)($metrics_store['text_model'] ?? '') . ' / ' . (string)($metrics_store['image_model'] ?? ''), ' /'),
                'usage' => [
                    'prompt_tokens' => (int)($metrics_store['prompt_tokens'] ?? 0),
                    'output_tokens' => (int)($metrics_store['output_tokens'] ?? 0),
                    'total_tokens' => (int)($metrics_store['total_tokens'] ?? 0),
                    'tool_tokens' => (int)($metrics_store['tool_tokens'] ?? 0),
                    'thought_tokens' => (int)($metrics_store['thought_tokens'] ?? 0),
                    'cached_tokens' => (int)($metrics_store['cached_tokens'] ?? 0),
                ],
            ], 200000);
            update_post_meta($post_id, '_aiblogger_usage_logged', 1);
        }

        return $post_id;
    }

    /**
     * Devuelve nombres de categorÃƒÆ’Ã‚Â­as existentes (para guiar al modelo).
     */
    public static function get_existing_category_names(string $post_type, int $limit = 40): array {
        $taxes = get_object_taxonomies($post_type, 'names');
        if (!in_array('category', $taxes, true)) return [];

        $terms = get_terms([
            'taxonomy' => 'category',
            'hide_empty' => false,
            'number' => max(1, $limit),
        ]);
        if (is_wp_error($terms) || !is_array($terms)) return [];

        $names = [];
        foreach ($terms as $t) {
            if (!empty($t->name)) $names[] = (string)$t->name;
        }
        return $names;
    }

    private static function assign_terms(int $post_id, string $post_type, array $gen, array $params): void {
        $taxes = get_object_taxonomies($post_type, 'names');

        // --- CategorÃƒÆ’Ã‚Â­a ---
        if (in_array('category', $taxes, true)) {
            $suggest = [];
            if (!empty($gen['category_suggestions']) && is_array($gen['category_suggestions'])) {
                $suggest = array_values(array_filter(array_map('sanitize_text_field', $gen['category_suggestions'])));
            }

            $cat_id = self::pick_existing_category_id($suggest);
            if ($cat_id) {
                wp_set_post_terms($post_id, [(int)$cat_id], 'category', false);
            }
        }

        // --- Tags ---
        if (in_array('post_tag', $taxes, true)) {
            $tags = [];
            if (!empty($gen['tags']) && is_array($gen['tags'])) {
                $tags = array_slice(array_map('sanitize_text_field', $gen['tags']), 0, 15);
            }

            $focus = !empty($gen['focus_keyword']) ? sanitize_text_field($gen['focus_keyword']) : '';
            $title = !empty($gen['title']) ? sanitize_text_field($gen['title']) : '';

            $reuse = !empty($params['reuse_existing_terms']);
            $allow_create = !empty($params['allow_create_new_tags']);

            $term_ids = [];
            $names_to_create = [];

            if ($reuse) {
                $existing = self::existing_term_maps('post_tag');
                foreach ($tags as $t) {
                    $key = self::term_key($t);
                    if (isset($existing[$key])) {
                        $term_ids[] = (int)$existing[$key];
                    } else {
                        $names_to_create[] = $t;
                    }
                }

                if ($allow_create && $names_to_create) {
                    $created = 0;
                    foreach ($names_to_create as $t) {
                        if ($created >= 2) break;
                        if (!self::is_essential_tag($t, $focus, $title)) continue;
                        $new = wp_insert_term($t, 'post_tag');
                        if (!is_wp_error($new) && !empty($new['term_id'])) {
                            $term_ids[] = (int)$new['term_id'];
                            $created++;
                        }
                    }
                }

                if ($term_ids) {
                    wp_set_post_terms($post_id, array_values(array_unique($term_ids)), 'post_tag', false);
                }

            } else {
                if ($tags) {
                    wp_set_post_terms($post_id, $tags, 'post_tag', false);
                }
            }
        }
    }

    private static function pick_existing_category_id(array $suggestions): int {
        if (!$suggestions) return 0;

        $existing = self::existing_term_maps('category');
        foreach ($suggestions as $name) {
            $key = self::term_key($name);
            if (isset($existing[$key])) return (int)$existing[$key];
        }

        foreach ($suggestions as $name) {
            $slug = sanitize_title($name);
            foreach ($existing as $k => $tid) {
                if ($k === $slug) return (int)$tid;
            }
        }

        return 0;
    }

    private static function existing_term_maps(string $taxonomy): array {
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'number' => 400,
        ]);
        if (is_wp_error($terms) || !is_array($terms)) return [];

        $map = [];
        foreach ($terms as $t) {
            $name = (string)($t->name ?? '');
            $slug = (string)($t->slug ?? '');
            $id = (int)($t->term_id ?? 0);
            if ($id <= 0) continue;
            if ($name) $map[self::term_key($name)] = $id;
            if ($slug) $map[self::term_key($slug)] = $id;
        }
        return $map;
    }

    private static function term_key(string $s): string {
        $s = strtolower(trim($s));
        $s = remove_accents($s);
        $s = preg_replace('/\s+/', ' ', $s);
        $s = preg_replace('/[^a-z0-9\- ]+/', '', $s);
        return trim($s);
    }

    private static function is_essential_tag(string $tag, string $focus_keyword, string $title): bool {
        $t = self::term_key($tag);
        if (!$t) return false;

        if ($focus_keyword) {
            if ($t === self::term_key($focus_keyword)) return true;
        }
        if ($title) {
            if (strpos(self::term_key($title), $t) !== false) return true;
        }
        return false;
    }

    public static function sanitize_content(string $html): string {
        $allowed = [
            'p' => [],
            'h2' => [],
            'h3' => [],
            'h4' => [],
            'ul' => [],
            'ol' => [],
            'li' => [],
            'strong' => [],
            'em' => [],
            'a' => [
                'href' => true,
                'title' => true,
                'target' => true,
                'rel' => true,
            ],
            'figure' => ['class' => true],
            'img' => [
                'src' => true,
                'alt' => true,
                'srcset' => true,
                'sizes' => true,
                'width' => true,
                'height' => true,
                'class' => true,
                'loading' => true,
            ],
        ];

        return wp_kses($html, $allowed);
    }

    /**
     * $internal_images: array of [ 'attachment_id'=>int, 'alt'=>string, 'anchor_heading'=>string ]
     */
    private static function inject_internal_images(string $html, array $internal_images, string $display_size): string {
        if (!$html || empty($internal_images)) return $html;

        // Si no hay headings, insertamos despuÃƒÆ’Ã‚Â©s de cada 2 pÃƒÆ’Ã‚Â¡rrafos.
        if (stripos($html, '<h2') === false && stripos($html, '<h3') === false && stripos($html, '<h4') === false) {
            $parts = preg_split('/(<p[^>]*>.*?<\/p>)/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
            $out = '';
            $pCount = 0;
            $imgIdx = 0;
            foreach ($parts as $chunk) {
                $out .= $chunk;
                if (preg_match('/^<p[^>]*>.*<\/p>$/is', trim($chunk))) {
                    $pCount++;
                    if ($pCount % 2 === 0 && isset($internal_images[$imgIdx])) {
                        $aid = (int)($internal_images[$imgIdx]['attachment_id'] ?? 0);
                        if ($aid > 0) {
                            $out .= "\n" . AIBlogger_Image_Handler::get_img_html($aid, $display_size, (string)($internal_images[$imgIdx]['alt'] ?? ''));
                            $imgIdx++;
                        }
                    }
                }
            }
            while (isset($internal_images[$imgIdx])) {
                $aid = (int)($internal_images[$imgIdx]['attachment_id'] ?? 0);
                if ($aid > 0) $out .= "\n" . AIBlogger_Image_Handler::get_img_html($aid, $display_size, (string)($internal_images[$imgIdx]['alt'] ?? ''));
                $imgIdx++;
            }
            return $out;
        }

        // Partimos por headings y agrupamos en secciones.
        $parts = preg_split('/(<h2[^>]*>.*?<\/h2>|<h3[^>]*>.*?<\/h3>|<h4[^>]*>.*?<\/h4>)/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts) || count($parts) < 2) return $html;

        $intro = array_shift($parts);
        $sections = [];
        for ($i = 0; $i < count($parts); $i += 2) {
            $heading = $parts[$i] ?? '';
            $body = $parts[$i + 1] ?? '';
            if (!$heading) continue;
            $sections[] = [
                'heading' => $heading,
                'heading_text' => self::heading_text($heading),
                'body' => $body,
            ];
        }

        $schedule = []; // section_index => [image indexes...]
        $used = array_fill(0, count($internal_images), false);

        // 1) Intentar insert por anchor_heading
        foreach ($internal_images as $imgIndex => $img) {
            $anchor = trim((string)($img['anchor_heading'] ?? ''));
            if (!$anchor) continue;
            $needle = self::normalize_text($anchor);
            if (!$needle) continue;

            foreach ($sections as $sidx => $sec) {
                if (self::normalize_text($sec['heading_text']) === $needle) {
                    $schedule[$sidx][] = $imgIndex;
                    $used[$imgIndex] = true;
                    break;
                }
            }
        }

        // 2) Resto: insertar como pausa cada 2 secciones
        $remaining = [];
        foreach ($internal_images as $imgIndex => $img) {
            if (empty($used[$imgIndex])) $remaining[] = $imgIndex;
        }

        $remPtr = 0;
        for ($sidx = 0; $sidx < count($sections) && $remPtr < count($remaining); $sidx++) {
            $section_number = $sidx + 1;
            if ($section_number % 2 === 0) {
                $schedule[$sidx][] = $remaining[$remPtr];
                $remPtr++;
            }
        }

        // Construir salida
        $out = $intro;
        for ($sidx = 0; $sidx < count($sections); $sidx++) {
            $out .= $sections[$sidx]['heading'] . $sections[$sidx]['body'];

            if (!empty($schedule[$sidx])) {
                foreach ($schedule[$sidx] as $imgIndex) {
                    $aid = (int)($internal_images[$imgIndex]['attachment_id'] ?? 0);
                    if ($aid > 0) {
                        $out .= "\n" . AIBlogger_Image_Handler::get_img_html($aid, $display_size, (string)($internal_images[$imgIndex]['alt'] ?? ''));
                    }
                }
            }
        }

        while ($remPtr < count($remaining)) {
            $imgIndex = $remaining[$remPtr];
            $aid = (int)($internal_images[$imgIndex]['attachment_id'] ?? 0);
            if ($aid > 0) {
                $out .= "\n" . AIBlogger_Image_Handler::get_img_html($aid, $display_size, (string)($internal_images[$imgIndex]['alt'] ?? ''));
            }
            $remPtr++;
        }

        return $out;
    }

    private static function heading_text(string $heading_html): string {
        return trim(wp_strip_all_tags($heading_html));
    }

    private static function normalize_text(string $s): string {
        $s = strtolower(trim($s));
        $s = remove_accents($s);
        $s = preg_replace('/\s+/', ' ', $s);
        $s = preg_replace('/[^a-z0-9 ]+/', '', $s);
        return trim($s);
    }

    private static function store_generation_meta(int $post_id, array $params): void {
        $media_ids = [];
        if (!empty($params['media_ids']) && is_array($params['media_ids'])) {
            foreach ($params['media_ids'] as $mid) {
                $mid = (int)$mid;
                if ($mid > 0) $media_ids[] = $mid;
            }
        }
        $media_ids = array_values(array_unique($media_ids));

        $reference_urls = [];
        if (!empty($params['reference_urls']) && is_array($params['reference_urls'])) {
            foreach ($params['reference_urls'] as $u) {
                $u = esc_url_raw((string)$u);
                if ($u) $reference_urls[] = $u;
            }
        }
        $reference_urls = array_values(array_unique($reference_urls));

        $meta = [
            'topic' => sanitize_text_field((string)($params['topic'] ?? '')),
            'language' => sanitize_text_field((string)($params['language'] ?? '')),
            'post_type' => sanitize_key((string)($params['post_type'] ?? 'post')),
            'post_status' => sanitize_key((string)($params['post_status'] ?? 'draft')),
            'min_words' => max(0, (int)($params['min_words'] ?? 0)),
            'max_words' => max(0, (int)($params['max_words'] ?? 0)),
            'featured_aspect_ratio' => sanitize_text_field((string)($params['featured_aspect_ratio'] ?? '')),
            'internal_aspect_ratio' => sanitize_text_field((string)($params['internal_aspect_ratio'] ?? '')),
            'internal_images_count' => max(0, (int)($params['internal_images_count'] ?? 0)),
            'reference_urls' => $reference_urls,
            'media_ids' => $media_ids,
            'saved_at' => time(),
        ];
        update_post_meta($post_id, '_aiblogger_generation_params', $meta);

        $prompt = (string)($params['generation_prompt'] ?? '');
        if ($prompt !== '') {
            if (function_exists('mb_substr')) {
                $prompt = (string)mb_substr($prompt, 0, 120000);
            } else {
                $prompt = (string)substr($prompt, 0, 120000);
            }
            update_post_meta($post_id, '_aiblogger_generation_prompt', $prompt);
        }
    }

    private static function maybe_convert_html_to_blocks(string $html): string {
        $html = trim($html);
        if ($html === '' || strpos($html, '<!-- wp:') !== false) return $html;
        if (!class_exists('DOMDocument')) return $html;

        $prev = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $wrapped = '<!DOCTYPE html><html><body>' . $html . '</body></html>';
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        if (!$loaded) {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            return $html;
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            return $html;
        }

        $blocks = [];
        foreach ($body->childNodes as $node) {
            $chunk = self::html_node_to_block($node);
            if ($chunk !== '') $blocks[] = $chunk;
        }

        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$blocks) return $html;
        return implode("\n\n", $blocks);
    }

    private static function html_node_to_block(DOMNode $node): string {
        if ($node instanceof DOMText) {
            $text = trim((string)$node->nodeValue);
            if ($text === '') return '';
            return "<!-- wp:paragraph -->\n<p>" . esc_html($text) . "</p>\n<!-- /wp:paragraph -->";
        }

        if (!($node instanceof DOMElement)) return '';

        $tag = strtolower($node->tagName);
        $outer = trim(self::outer_html($node));
        if ($outer === '') return '';

        if ($tag === 'p') {
            if (trim(wp_strip_all_tags($outer)) === '') return '';
            return "<!-- wp:paragraph -->\n{$outer}\n<!-- /wp:paragraph -->";
        }

        if (in_array($tag, ['h2', 'h3', 'h4', 'h5', 'h6'], true)) {
            $level = (int)substr($tag, 1);
            $attrs = ' ' . wp_json_encode(['level' => $level], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return "<!-- wp:heading{$attrs} -->\n{$outer}\n<!-- /wp:heading -->";
        }

        if ($tag === 'ul') {
            return "<!-- wp:list -->\n{$outer}\n<!-- /wp:list -->";
        }
        if ($tag === 'ol') {
            return "<!-- wp:list {\"ordered\":true} -->\n{$outer}\n<!-- /wp:list -->";
        }

        if ($tag === 'blockquote') {
            return "<!-- wp:quote -->\n{$outer}\n<!-- /wp:quote -->";
        }

        if ($tag === 'figure' || $tag === 'img') {
            $img_node = null;
            $figure_classes = [];

            if ($tag === 'figure') {
                $figure_classes = self::class_tokens((string)$node->getAttribute('class'));
                foreach ($node->getElementsByTagName('img') as $candidate) {
                    if ($candidate instanceof DOMElement) {
                        $img_node = $candidate;
                        break;
                    }
                }
            } else {
                $img_node = $node;
            }

            if ($img_node instanceof DOMElement) {
                $block = self::build_image_block($img_node, $figure_classes);
                if ($block !== '') return $block;
            }
        }

        return "<!-- wp:html -->\n{$outer}\n<!-- /wp:html -->";
    }

    private static function build_image_block(DOMElement $img_node, array $figure_classes = []): string {
        $src = trim((string)$img_node->getAttribute('src'));
        $alt = trim((string)$img_node->getAttribute('alt'));

        $img_classes = self::class_tokens((string)$img_node->getAttribute('class'));
        $classes = array_values(array_unique(array_merge($figure_classes, $img_classes)));

        $aid = self::extract_attachment_id_from_classes($classes);
        $size_slug = self::extract_size_slug_from_classes($classes);
        if ($size_slug === '' && $src !== '' && preg_match('/-opt\.webp(?:\?|$)/i', $src)) {
            $size_slug = 'aiblogger_opt';
        }

        $align = self::extract_align_from_classes($classes);

        $attrs = [];
        if ($aid > 0) $attrs['id'] = $aid;
        if ($size_slug !== '') $attrs['sizeSlug'] = $size_slug;
        if ($align !== '') $attrs['align'] = $align;

        $img_tag = '';
        if ($aid > 0) {
            $img_tag = self::build_image_tag_for_attachment($aid, $size_slug ?: 'large', $alt);
            if ($img_tag === '') {
                $src = AIBlogger_Image_Handler::get_image_url($aid, $size_slug ?: 'large')
                    ?: AIBlogger_Image_Handler::get_image_url($aid, 'full')
                    ?: $src;
            }
        }

        if ($img_tag === '') {
            if ($src === '') return '';

            $img_tag = '<img src="' . esc_url($src) . '" alt="' . esc_attr($alt) . '" loading="lazy" decoding="async"';
            $w = (int)$img_node->getAttribute('width');
            $h = (int)$img_node->getAttribute('height');
            if ($w > 0) $img_tag .= ' width="' . $w . '"';
            if ($h > 0) $img_tag .= ' height="' . $h . '"';

            $img_class = [];
            if ($aid > 0) $img_class[] = 'wp-image-' . $aid;
            if (in_array('aiblogger-opt', $classes, true)) $img_class[] = 'aiblogger-opt';
            if ($img_class) {
                $img_tag .= ' class="' . esc_attr(implode(' ', array_values(array_unique($img_class)))) . '"';
            }

            $img_tag .= ' />';
        }

        $figure_class = ['wp-block-image'];
        if ($align !== '') $figure_class[] = 'align' . $align;
        if ($size_slug !== '') $figure_class[] = 'size-' . $size_slug;

        $figure_html = '<figure class="' . esc_attr(implode(' ', array_values(array_unique($figure_class)))) . '">' . $img_tag . '</figure>';
        $attrs_json = $attrs ? ' ' . wp_json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';

        return "<!-- wp:image{$attrs_json} -->\n{$figure_html}\n<!-- /wp:image -->";
    }

    private static function class_tokens(string $class_attr): array {
        if ($class_attr === '') return [];
        $parts = preg_split('/\s+/', trim($class_attr));
        if (!is_array($parts)) return [];
        return array_values(array_filter(array_map('sanitize_html_class', $parts)));
    }

    private static function extract_attachment_id_from_classes(array $classes): int {
        foreach ($classes as $cls) {
            if (preg_match('/^wp-image-(\d+)$/i', (string)$cls, $m)) {
                return (int)$m[1];
            }
        }
        return 0;
    }

    private static function extract_size_slug_from_classes(array $classes): string {
        foreach ($classes as $cls) {
            if (preg_match('/^size-([a-z0-9_-]+)$/i', (string)$cls, $m)) {
                return sanitize_key((string)$m[1]);
            }
        }
        return '';
    }

    private static function extract_align_from_classes(array $classes): string {
        foreach ($classes as $cls) {
            if (preg_match('/^align(left|center|right|wide|full)$/i', (string)$cls, $m)) {
                return strtolower((string)$m[1]);
            }
        }
        return '';
    }

    private static function build_image_tag_for_attachment(int $attachment_id, string $size_slug, string $alt): string {
        $figure = AIBlogger_Image_Handler::get_img_html($attachment_id, $size_slug, $alt);
        if (preg_match('/<img\b[^>]*>/i', $figure, $m)) {
            return (string)$m[0];
        }
        return '';
    }

    private static function outer_html(DOMNode $node): string {
        if (!$node->ownerDocument) return '';
        $html = $node->ownerDocument->saveHTML($node);
        return is_string($html) ? $html : '';
    }

    private static function extract_attachment_id_from_html(string $html): int {
        if (preg_match('/\\bwp-image-(\\d+)\\b/i', $html, $m)) {
            return (int)$m[1];
        }
        return 0;
    }

    private static function extract_size_slug_from_html(string $html): string {
        if (preg_match('/\\bsize-([a-z0-9_-]+)\\b/i', $html, $m)) {
            return sanitize_key((string)$m[1]);
        }
        return '';
    }
}
