<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Renders post content for indexing in a fixed, predictable context.
 *
 * Indexing runs from admin requests, cron, background jobs and WP-CLI. Rendering
 * `the_content` as whoever happens to be the current user makes the result depend
 * on that user: content restriction plugins replace the page with their
 * "restricted" text for anonymous runs. Rendering also must not pick up PHP
 * notices or debug output that WP_DEBUG_DISPLAY prints inside buffered block output.
 */
class IndexingRenderContext {
    public const FILTER_INDEXING_USER_ID = 'geweb_aisearch_indexing_user_id';
    public const FILTER_RENDERED_HTML = 'geweb_aisearch_indexing_rendered_html';

    /**
     * Render a post's content through `the_content` as the indexing user.
     */
    public static function renderPostContent(\WP_Post $post): string {
        $html = (string) self::runAsIndexingUser(
            static fn() => apply_filters('the_content', $post->post_content)
        );

        /**
         * Filters the rendered HTML of a post before it is converted for indexing.
         *
         * @param string   $html Rendered HTML.
         * @param \WP_Post $post Post being indexed.
         */
        return (string) apply_filters(self::FILTER_RENDERED_HTML, $html, $post);
    }

    /**
     * Run a callback as the indexing user, with PHP error display switched off and any
     * direct output discarded. The previous user and settings are always restored.
     *
     * @param callable $callback
     * @return mixed
     */
    public static function runAsIndexingUser(callable $callback) {
        $previousUserId = get_current_user_id();
        $indexingUserId = self::resolveIndexingUserId();
        $switchUser = $indexingUserId > 0 && $indexingUserId !== $previousUserId;
        $previousDisplayErrors = ini_get('display_errors');

        ini_set('display_errors', '0');
        // Easy Table of Contents adds a debug box to the content for administrators
        // when WP_DEBUG is on; the indexing user is usually an administrator.
        add_filter('Eztoc/Table_Of_Contents/Debug/Enabled', '__return_false', 999);
        add_filter('Eztoc/Table_Of_Contents/Debug/Display', '__return_false', 999);
        ob_start();
        try {
            if ($switchUser) {
                wp_set_current_user($indexingUserId);
            }

            return $callback();
        } finally {
            ob_end_clean();
            remove_filter('Eztoc/Table_Of_Contents/Debug/Enabled', '__return_false', 999);
            remove_filter('Eztoc/Table_Of_Contents/Debug/Display', '__return_false', 999);
            if ($switchUser) {
                wp_set_current_user($previousUserId);
            }
            if ($previousDisplayErrors !== false) {
                ini_set('display_errors', $previousDisplayErrors);
            }
        }
    }

    /**
     * The user whose view of the content gets indexed. Defaults to the
     * administrator with the lowest ID; 0 keeps the current user.
     */
    public static function resolveIndexingUserId(): int {
        $defaultUserId = 0;
        $administrators = get_users([
            'role' => 'administrator',
            'number' => 1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ID',
        ]);
        if (is_array($administrators) && !empty($administrators)) {
            $defaultUserId = (int) reset($administrators);
        }

        /**
         * Filters the user ID used to render content for indexing.
         *
         * @param int $defaultUserId Lowest-ID administrator, or 0 when there is none.
         */
        return max(0, (int) apply_filters(self::FILTER_INDEXING_USER_ID, $defaultUserId));
    }
}
