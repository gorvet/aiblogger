<?php
defined('ABSPATH') || exit;

class AIBlogger_Link_Suggester {

    /**
     * Suggest internal links by relevance using WP search.
     *
     * Returns: [ ['title'=>..., 'url'=>..., 'excerpt'=>...], ... ]
     */
    public static function suggest_internal(string $query, string $post_type = 'post', int $limit = 8): array {
        $query = trim($query);
        $limit = max(0, min(20, $limit));
        if ($query === '' || $limit === 0) return [];

        $pt = sanitize_key($post_type ?: 'post');

        $out = self::query_posts([
            'post_type' => $pt,
            'post_status' => 'publish',
            's' => $query,
            'posts_per_page' => $limit,
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
        ]);

        // Fallback: if search by topic returns nothing, use latest posts.
        if (!$out) {
            $out = self::query_posts([
                'post_type' => $pt,
                'post_status' => 'publish',
                'posts_per_page' => $limit,
                'orderby' => 'date',
                'order' => 'DESC',
                'no_found_rows' => true,
                'ignore_sticky_posts' => true,
            ]);
        }

        $seen = [];
        $dedup = [];
        foreach ($out as $row) {
            $u = (string)($row['url'] ?? '');
            if (!$u || isset($seen[$u])) continue;
            $seen[$u] = true;
            $dedup[] = $row;
        }

        return array_slice($dedup, 0, $limit);
    }

    private static function query_posts(array $args): array {
        $q = new WP_Query($args);
        $rows = [];

        if ($q->have_posts()) {
            foreach ($q->posts as $p) {
                $pid = (int)($p->ID ?? 0);
                if ($pid <= 0) continue;

                $url = get_permalink($pid);
                if (!$url) continue;

                $title = get_the_title($pid);
                $excerpt = wp_strip_all_tags(get_the_excerpt($pid));
                if (!$excerpt) {
                    $excerpt = wp_strip_all_tags((string)($p->post_content ?? ''));
                }

                $rows[] = [
                    'title' => (string)$title,
                    'url' => (string)$url,
                    'excerpt' => AIBlogger_Utils::safe_excerpt((string)$excerpt, 220),
                ];
            }
        }

        wp_reset_postdata();
        return $rows;
    }
}
