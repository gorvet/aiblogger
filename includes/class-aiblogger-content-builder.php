<?php
defined('ABSPATH') || exit;

class AIBlogger_Content_Builder {

    public static function schema(int $internal_images_count): array {
        $internal_images_count = max(0, min(10, (int)$internal_images_count));
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'TÃ­tulo SEO atractivo. MÃ¡x 55 caracteres.'],
                'slug'  => ['type' => 'string', 'description' => 'Slug corto (sin espacios).'],
                'excerpt' => ['type' => 'string', 'description' => 'Extracto de 1-2 lÃ­neas.'],
                'seo_meta_description' => ['type' => 'string', 'description' => 'Meta description 145â€“150 caracteres.'],
                'focus_keyword' => ['type' => 'string', 'description' => 'Keyword principal.'],

                'category_suggestions' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => '1-3 categorÃ­as sugeridas (priorizadas).'
                ],

                'tags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => '3-6 tags cortas.'
                ],

                'content_html' => ['type' => 'string', 'description' => 'Contenido HTML del artÃ­culo (sin markdown).'],

                'featured_image_scene' => ['type' => 'string', 'description' => 'DescripciÃ³n breve de escena para la imagen destacada (sin texto overlay).'],
                'featured_image_alt' => ['type' => 'string', 'description' => 'Alt text para la imagen destacada.'],

                'internal_image_briefs' => [
                    'type' => 'array',
                    'minItems' => $internal_images_count,
                    'maxItems' => $internal_images_count,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'anchor_heading' => ['type' => 'string', 'description' => 'Encabezado cercano donde insertar la imagen.'],
                            'scene' => ['type' => 'string', 'description' => 'Descripcion de escena (sin texto en la imagen).'],
                            'alt' => ['type' => 'string', 'description' => 'Alt text (coherente con keyword).'],
                        ],
                        'required' => ['scene', 'alt', 'anchor_heading']
                    ],
                    'description' => 'Briefs de imagenes internas.'
                ],

                'warnings' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Advertencias si faltan fuentes, datos, etc.'
                ],
            ],
            'required' => [
                'title',
                'excerpt',
                'seo_meta_description',
                'content_html',
                'tags',
                'focus_keyword',
                'featured_image_scene',
                'featured_image_alt'
            ],
            'additionalProperties' => false,
        ];
    }

    public static function build_prompt(array $params): string {
        $topic = trim((string)($params['topic'] ?? ''));
        $extra = trim((string)($params['extra_instructions'] ?? ''));
        $language = (string)($params['language'] ?? 'es');
        $min_words = (int)($params['min_words'] ?? 800);
        $max_words = (int)($params['max_words'] ?? 1500);
        $site_context = trim((string)($params['site_context'] ?? ''));
        $references = $params['references'] ?? [];
        $internal_images_count = (int)($params['internal_images_count'] ?? 0);
        $existing_categories = $params['existing_categories'] ?? [];

        $lang_name = self::language_name($language);

        // Datos (sin copy): banderas, lÃ­mites y listas.
        $internal_links_enabled = !empty($params['enable_internal_links']) ? '1' : '0';
        $max_internal_links = (int)($params['internal_links_max'] ?? 0);

        $external_links_enabled = !empty($params['enable_external_links']) ? '1' : '0';
        $max_external_links = (int)($params['external_links_max'] ?? 0);

        $internal_links_list = self::build_internal_links_list($params);
        $external_links_list = self::build_external_links_list($params);

        $ref_block = self::build_references_block($references);
        $cats_block = self::build_categories_block($existing_categories);

        $tpl = AIBlogger_Prompts::get('article-json', 'PROMPT_NOT_FOUND: article-json');

        $prompt = AIBlogger_Prompts::render($tpl, [
            'LANG_NAME' => $lang_name,
            'MIN_WORDS' => (string)$min_words,
            'MAX_WORDS' => (string)$max_words,
            'SITE_CONTEXT' => $site_context !== '' ? $site_context : 'N/A',
            'TOPIC' => $topic !== '' ? $topic : 'N/A',
            'EXTRA' => $extra !== '' ? $extra : 'N/A',

            'REFERENCES_BLOCK' => $ref_block !== '' ? $ref_block : 'N/A',
            'CATS_BLOCK' => $cats_block !== '' ? $cats_block : 'N/A',

            'INTERNAL_IMAGES_COUNT' => (string)$internal_images_count,

            'INTERNAL_LINKS_ENABLED' => $internal_links_enabled,
            'MAX_INTERNAL_LINKS' => (string)max(0, $max_internal_links),
            'INTERNAL_LINKS_LIST' => $internal_links_list !== '' ? $internal_links_list : '(vacÃ­o)',

            'EXTERNAL_LINKS_ENABLED' => $external_links_enabled,
            'MAX_EXTERNAL_LINKS' => (string)max(0, $max_external_links),
            'EXTERNAL_LINKS_LIST' => $external_links_list !== '' ? $external_links_list : '(vacÃ­o)',
        ]);

        return trim($prompt);
    }

    /**
     * Devuelve SOLO datos: lista de referencias (sin reglas).
     * Formato: lÃ­neas tipo "- TÃ­tulo | URL | Extracto".
     */
    private static function build_references_block($references): string {
        if (!is_array($references) || !$references) return '';

        $lines = [];
        foreach ($references as $r) {
            $url = trim((string)($r['url'] ?? ''));
            if ($url === '') continue;
            $title = trim((string)($r['title'] ?? ''));
            $txt = trim((string)($r['text'] ?? ''));
            $row = "- " . ($title !== '' ? $title : 'Referencia') . " | {$url}";
            if ($txt !== '') $row .= " | {$txt}";
            $lines[] = $row;
        }
        return $lines ? implode("\n", $lines) : '';
    }

    private static function build_categories_block($existing_categories): string {
        if (!is_array($existing_categories) || empty($existing_categories)) return '';
        $cats = array_slice($existing_categories, 0, 40);
        $cats = array_map(function($c){ return trim((string)$c); }, $cats);
        $cats = array_filter($cats, function($c){ return $c !== ''; });
        return $cats ? "- " . implode("\n- ", $cats) : '';
    }

    /**
     * Devuelve SOLO datos: lista de candidatos internos (sin reglas).
     * Formato: lÃ­neas tipo "- TÃ­tulo | URL | Resumen".
     */
    private static function build_internal_links_list(array $params): string {
        $enable = !empty($params['enable_internal_links']);
        $max = (int)($params['internal_links_max'] ?? 0);
        if (!$enable || $max <= 0) return '';

        $cands = $params['internal_link_candidates'] ?? [];
        if (!is_array($cands) || !$cands) return '';

        $lines = [];
        foreach (array_slice($cands, 0, 20) as $c) {
            $url = trim((string)($c['url'] ?? ''));
            if ($url === '') continue;
            $title = trim((string)($c['title'] ?? $url));
            $ex = trim((string)($c['excerpt'] ?? ''));
            $row = "- {$title} | {$url}";
            if ($ex !== '') $row .= " | {$ex}";
            $lines[] = $row;
        }

        return $lines ? implode("\n", $lines) : '';
    }

    /**
     * Devuelve SOLO datos: lista de candidatos externos (sin reglas).
     * Formato: lÃ­neas tipo "- Etiqueta | URL".
     */
    private static function build_external_links_list(array $params): string {
        $enable = !empty($params['enable_external_links']);
        $max = (int)($params['external_links_max'] ?? 0);
        if (!$enable || $max <= 0) return '';

        $cands = $params['external_link_candidates'] ?? [];
        if (!is_array($cands) || !$cands) return '';

        $lines = [];
        foreach (array_slice($cands, 0, 20) as $c) {
            $url = trim((string)($c['url'] ?? ''));
            if ($url === '') continue;
            $title = trim((string)($c['title'] ?? $url));
            $lines[] = "- {$title} | {$url}";
        }

        return $lines ? implode("\n", $lines) : '';
    }

    public static function build_featured_image_prompt(string $scene, array $params): string {
        $ar = (string)($params['featured_aspect_ratio'] ?? '16:9');

        // Fallback mÃ­nimo (sin copy creativo). Idealmente el prompt vive en assets/prompts/image-featured.txt
        $tpl = AIBlogger_Prompts::get('image-featured', "ASPECT_RATIO={{ASPECT_RATIO}}\nSCENE={{SCENE}}");

        return trim(AIBlogger_Prompts::render($tpl, [
            'ASPECT_RATIO' => $ar,
            'SCENE' => $scene,
        ]));
    }

    public static function build_internal_image_prompt(string $scene, array $params): string {
        $ar = (string)($params['internal_aspect_ratio'] ?? '4:5');

        // Fallback mÃ­nimo (sin copy creativo). Idealmente el prompt vive en assets/prompts/image-internal.txt
        $tpl = AIBlogger_Prompts::get('image-internal', "ASPECT_RATIO={{ASPECT_RATIO}}\nSCENE={{SCENE}}");

        return trim(AIBlogger_Prompts::render($tpl, [
            'ASPECT_RATIO' => $ar,
            'SCENE' => $scene,
        ]));
    }

    public static function language_name(string $code): string {
        $code = strtolower(trim($code));
        $map = [
            'es' => 'EspaÃ±ol',
            'en' => 'English',
            'pt' => 'PortuguÃªs',
            'fr' => 'FranÃ§ais',
            'it' => 'Italiano',
            'de' => 'Deutsch',
        ];
        return $map[$code] ?? $code;
    }
}
