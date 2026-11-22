<?php
declare(strict_types=1);

/**
 * Minimal WordPress stubs and plugin class autoloader for unit tests.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

$testRoot = sys_get_temp_dir() . '/avpvh-ai-search-tests-' . getmypid();
if (!is_dir($testRoot . '/wp-content/uploads')) {
    mkdir($testRoot . '/wp-content/uploads', 0777, true);
}

if (!defined('ABSPATH')) {
    define('ABSPATH', rtrim($testRoot, '/\\') . '/');
}
if (!defined('AVPVH_SRCH_PATH')) {
    define('AVPVH_SRCH_PATH', dirname(__DIR__) . '/');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

$GLOBALS['avpvh_test_options'] = [];
$GLOBALS['avpvh_test_shortcodes'] = [];
$GLOBALS['avpvh_test_uploads_dir'] = $testRoot . '/wp-content/uploads';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Geweb\\AISearch\\';
    $baseDir = dirname(__DIR__) . '/classes/';
    static $fallbackMap = null;

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require_once $file;
        return;
    }

    if ($fallbackMap === null) {
        $fallbackMap = [];
        foreach (glob($baseDir . '*/*.php') ?: [] as $nestedFile) {
            $fallbackMap[basename($nestedFile, '.php')] = $nestedFile;
        }
    }

    if (isset($fallbackMap[$relativeClass]) && file_exists($fallbackMap[$relativeClass])) {
        require_once $fallbackMap[$relativeClass];
    }
});

if (!function_exists('wp_normalize_path')) {
    function wp_normalize_path(string $path): string {
        return preg_replace('#/+#', '/', str_replace('\\', '/', $path)) ?? $path;
    }
}
if (!function_exists('trailingslashit')) {
    function trailingslashit(string $value): string {
        return rtrim($value, '/\\') . '/';
    }
}
if (!function_exists('untrailingslashit')) {
    function untrailingslashit(string $value): string {
        return rtrim($value, '/\\');
    }
}
if (!function_exists('wp_get_upload_dir')) {
    function wp_get_upload_dir(): array {
        return [
            'basedir' => $GLOBALS['avpvh_test_uploads_dir'],
            'baseurl' => 'https://example.test/wp-content/uploads',
        ];
    }
}
if (!function_exists('home_url')) {
    function home_url(string $path = ''): string {
        return 'https://example.test' . $path;
    }
}
if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string {
        return 'https://example.test/wp-admin/' . ltrim($path, '/');
    }
}
if (!function_exists('add_query_arg')) {
    function add_query_arg($args, $urlOrVal = '', $url = ''): string {
        if (is_array($args)) {
            $target = (string) $urlOrVal;
            $sep = strpos($target, '?') === false ? '?' : '&';
            return $target . $sep . http_build_query($args);
        }
        $target = (string) $url;
        $sep = strpos($target, '?') === false ? '?' : '&';
        return $target . $sep . http_build_query([(string) $args => $urlOrVal]);
    }
}
if (!function_exists('shortcode_exists')) {
    function shortcode_exists(string $tag): bool {
        return !empty($GLOBALS['avpvh_test_shortcodes'][$tag]);
    }
}
if (!function_exists('has_shortcode')) {
    function has_shortcode(string $content, string $tag): bool {
        return strpos($content, '[' . $tag) !== false;
    }
}
if (!function_exists('maybe_unserialize')) {
    function maybe_unserialize($data) {
        return $data;
    }
}
if (!function_exists('get_option')) {
    function get_option(string $name, $default = false) {
        return array_key_exists($name, $GLOBALS['avpvh_test_options']) ? $GLOBALS['avpvh_test_options'][$name] : $default;
    }
}
if (!function_exists('update_option')) {
    function update_option(string $name, $value, $autoload = null): bool {
        $GLOBALS['avpvh_test_options'][$name] = $value;
        return true;
    }
}
if (!function_exists('delete_option')) {
    function delete_option(string $name): bool {
        unset($GLOBALS['avpvh_test_options'][$name]);
        return true;
    }
}
if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string {
        return $text;
    }
}
if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_html')) {
    function esc_html(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_url')) {
    function esc_url(string $url): string {
        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
    }
}
if (!function_exists('sanitize_html_class')) {
    function sanitize_html_class(string $class): string {
        return preg_replace('/[^A-Za-z0-9_-]/', '', $class) ?? '';
    }
}
if (!function_exists('checked')) {
    function checked($checked, $current = true, bool $display = true): string {
        $res = ((string) $checked === (string) $current) ? " checked='checked'" : '';
        if ($display) {
            echo $res;
        }
        return $res;
    }
}
if (!function_exists('get_posts')) {
    function get_posts(array $args = []): array {
        return [];
    }
}

if (!class_exists('WP_Post')) {
    class WP_Post {
        public string $post_content = '';
        public function __construct(string $content = '') {
            $this->post_content = $content;
        }
    }
}

if (!class_exists('WP_List_Table')) {
    class WP_List_Table {
        public function __construct(array $args = []) {}
        protected function single_row_columns($item): void {}
    }
}

class AvpvhTestWpdb {
    public string $options = 'wp_options';
    public string $prefix = 'wp_';

    public function esc_like(string $text): string {
        return addcslashes($text, '_%\\');
    }

    public function prepare(string $query, ...$args): string {
        foreach ($args as $arg) {
            $query = preg_replace('/%s/', "'" . addslashes((string) $arg) . "'", $query, 1) ?? $query;
        }
        return $query;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function get_results(string $query, $output = ARRAY_A): array {
        $results = [];
        if (preg_match_all("/option_name LIKE '([^']+)'/", $query, $matches)) {
            $patterns = $matches[1];
            foreach ($GLOBALS['avpvh_test_options'] as $name => $value) {
                foreach ($patterns as $pattern) {
                    $literal = str_replace(['\\_', '\\%'], ['__UNDERSCORE__', '__PERCENT__'], $pattern);
                    $regex = '/^' . str_replace(['%', '__UNDERSCORE__', '__PERCENT__'], ['.*', '_', '%'], preg_quote($literal, '/')) . '$/i';
                    if (preg_match($regex, (string) $name)) {
                        $results[] = ['option_name' => (string) $name, 'option_value' => $value];
                        break;
                    }
                }
            }
        }
        return $results;
    }

    /**
     * @return array<int,string>
     */
    public function get_col(string $query): array {
        return [];
    }
}

$GLOBALS['wpdb'] = new AvpvhTestWpdb();
