<?php
declare(strict_types=1);

/**
 * Validates AV-PvH File List integration and legacy Simple File List fallback.
 */

$tempRoot = sys_get_temp_dir() . '/avpvh-filelist-test-' . bin2hex(random_bytes(4));
$uploadsDir = $tempRoot . '/wp-content/uploads';
$avpvhDir1 = $uploadsDir . '/avpvh-filelist';
$avpvhDir2 = $uploadsDir . '/list-two';
$legacyDir = $uploadsDir . '/simple-file-list';
mkdir($avpvhDir1, 0777, true);
mkdir($avpvhDir2, 0777, true);
mkdir($legacyDir, 0777, true);

file_put_contents($avpvhDir1 . '/13_ams_25_104_F_V_01.pdf', '%PDF-1.4 list 1');
file_put_contents($avpvhDir2 . '/report.pdf', '%PDF-1.4 list 2');
file_put_contents($avpvhDir1 . '/report.pdf', '%PDF-1.4 list 1 duplicate name');
file_put_contents($legacyDir . '/legacy_only.pdf', '%PDF-1.4 legacy');

define('ABSPATH', $tempRoot . '/');

$GLOBALS['test_options'] = [];
$GLOBALS['test_shortcodes'] = [];
$GLOBALS['test_uploads_dir'] = $uploadsDir;

function wp_normalize_path(string $path): string {
    return preg_replace('#/+#', '/', str_replace('\\', '/', $path)) ?? $path;
}
function trailingslashit(string $s): string {
    return rtrim($s, '/\\') . '/';
}
function untrailingslashit(string $s): string {
    return rtrim($s, '/\\');
}
function wp_get_upload_dir(): array {
    return [
        'basedir' => $GLOBALS['test_uploads_dir'],
        'baseurl' => 'https://example.test/wp-content/uploads',
    ];
}
function home_url(string $path = ''): string {
    return 'https://example.test' . $path;
}
function admin_url(string $path = ''): string {
    return 'https://example.test/wp-admin/' . ltrim($path, '/');
}
function add_query_arg(array $args, string $url): string {
    $sep = strpos($url, '?') === false ? '?' : '&';
    return $url . $sep . http_build_query($args);
}
function shortcode_exists(string $tag): bool {
    return !empty($GLOBALS['test_shortcodes'][$tag]);
}
function has_shortcode(string $content, string $tag): bool {
    return strpos($content, '[' . $tag) !== false;
}
function maybe_unserialize($data) {
    return $data;
}
function get_option(string $name, $default = false) {
    return array_key_exists($name, $GLOBALS['test_options']) ? $GLOBALS['test_options'][$name] : $default;
}
function update_option(string $name, $value, $autoload = null): bool {
    $GLOBALS['test_options'][$name] = $value;
    return true;
}

function esc_html(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}
function esc_attr(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}
function esc_url(string $url): string {
    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}
function sanitize_key(string $key): string {
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
}
function sanitize_html_class(string $class): string {
    return preg_replace('/[^A-Za-z0-9_-]/', '', $class) ?? '';
}
function checked($checked, $current = true, bool $display = true): string {
    $res = ((string) $checked === (string) $current) ? " checked='checked'" : '';
    if ($display) {
        echo $res;
    }
    return $res;
}
function get_posts(array $args = []): array {
    return [];
}

class WP_List_Table {
    public function __construct(array $args = []) {}
    protected function single_row_columns($item): void {}
}

class WP_Post {
    public string $post_content = '';
    public function __construct(string $content = '') {
        $this->post_content = $content;
    }
}

class FakeWpdb {
    public string $options = 'wp_options';
    public string $prefix = 'wp_';

    public function esc_like(string $text): string {
        return addcslashes($text, '_%\\');
    }

    public function prepare(string $query, ...$args): string {
        foreach ($args as $arg) {
            $query = preg_replace('/%s/', "'" . addslashes((string) $arg) . "'", $query, 1);
        }
        return $query;
    }

    public function get_results(string $query, $output = ARRAY_A): array {
        $results = [];
        if (preg_match_all("/option_name LIKE '([^']+)'/", $query, $matches)) {
            $patterns = $matches[1];
            foreach ($GLOBALS['test_options'] as $name => $value) {
                foreach ($patterns as $pattern) {
                    $literal = str_replace(['\\_', '\\%'], ['__UNDERSCORE__', '__PERCENT__'], $pattern);
                    $regex = '/^' . str_replace(['%', '__UNDERSCORE__', '__PERCENT__'], ['.*', '_', '%'], preg_quote($literal, '/')) . '$/i';
                    if (preg_match($regex, $name)) {
                        $results[] = ['option_name' => $name, 'option_value' => $value];
                        break;
                    }
                }
            }
        }
        return $results;
    }

    public function get_col(string $query): array {
        return [];
    }
}

define('ARRAY_A', 'ARRAY_A');
$GLOBALS['wpdb'] = new FakeWpdb();

require_once __DIR__ . '/../classes/Documents/SimpleFileListSupportHelpersTrait.php';
require_once __DIR__ . '/../classes/Documents/SimpleFileListSupport.php';
require_once __DIR__ . '/../classes/Documents/SimpleFileListNiceNameUpdater.php';
require_once __DIR__ . '/../classes/Documents/FileDuplicateHashIndex.php';
require_once __DIR__ . '/../classes/Documents/ReferencedDocumentListTableHelpersTrait.php';
require_once __DIR__ . '/../classes/Documents/ReferencedDocumentListTable.php';

use Geweb\AISearch\ReferencedDocumentListTable;
use Geweb\AISearch\SimpleFileListNiceNameUpdater;
use Geweb\AISearch\SimpleFileListSupport;

function assertTrue(bool $cond, string $msg): void {
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

function assertSame($expected, $actual, string $msg): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$msg}\nExpected: " . var_export($expected, true) . "\nActual:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}

// 1. Legacy-only mode (no avpvh-filelist options or plugin)
$GLOBALS['test_shortcodes'] = ['eeSFL' => true];
$GLOBALS['test_options'] = [
    'eeSFL_Settings_1' => ['FileListDir' => 'wp-content/uploads/simple-file-list/'],
    'eeSFL_FileList_1' => [
        ['FilePath' => 'legacy_only.pdf', 'FileNiceName' => 'Legacy Doc', 'FileExt' => 'pdf', 'FileDateChanged' => '2025-06-12 02:00:00'],
    ],
];
$legacySupport = new SimpleFileListSupport();
assertSame(false, $legacySupport->isUsingAvpvhFileList(), 'Legacy mode should not report AV-PvH File List');
assertTrue($legacySupport->isSimpleFileListPage(new WP_Post('[eeSFL]')), 'Legacy [eeSFL] shortcode detected');
$legacyEntries = $legacySupport->getSimpleFileListEntries();
assertSame(1, count($legacyEntries), 'Legacy mode should resolve 1 entry');
assertSame('Legacy Doc', $legacyEntries[0]['display_name'], 'Legacy nice name resolved');
assertTrue(strpos($legacySupport->buildSimpleFileListAdminUrl($legacyEntries[0]['file_path']), 'page=ee-simple-file-list') !== false, 'Legacy admin URL points to ee-simple-file-list');

// 2. Migrated AV-PvH File List mode (both avpvh_flst_* and leftover eeSFL_* exist!)
$GLOBALS['test_shortcodes'] = ['avpvh_filelist' => true, 'eeSFL' => true];
$GLOBALS['test_options'] = [
    'eeSFL_Settings_1' => ['FileListDir' => 'wp-content/uploads/simple-file-list/'],
    'eeSFL_FileList_1' => [
        ['FilePath' => 'legacy_only.pdf', 'FileNiceName' => 'Stale Legacy', 'FileExt' => 'pdf'],
    ],
    'avpvh_flst_settings_1' => ['FileListDir' => 'wp-content/uploads/avpvh-filelist/'],
    'avpvh_flst_files_1' => [
        ['FilePath' => 'subdir', 'FileNiceName' => '', 'FileExt' => 'folder'],
        ['FilePath' => '13_ams_25_104_F_V_01.pdf', 'FileNiceName' => 'Verslag curator 1', 'FileExt' => 'pdf', 'FileDateChanged' => '2025-06-12 02:00:00'],
    ],
    'avpvh_flst_settings_2' => ['FileListDir' => 'wp-content/uploads/list-two/'],
    'avpvh_flst_files_2' => [
        ['FilePath' => 'report.pdf', 'FileNiceName' => 'List Two Report', 'FileExt' => 'pdf', 'FileDateChanged' => '2025-08-26 02:00:00'],
    ],
];

$support = new SimpleFileListSupport();
assertSame(true, $support->isUsingAvpvhFileList(), 'AV-PvH mode detected when avpvh_filelist is active/present');
assertTrue($support->isSimpleFileListPage(new WP_Post('[avpvh_filelist list="2"]')), 'Detects [avpvh_filelist] shortcode');
assertTrue($support->isSimpleFileListPage(new WP_Post('[eeSFL]')), 'Still detects [eeSFL] alias');

$entries = $support->getSimpleFileListEntries();
assertSame(2, count($entries), 'AV-PvH mode ignores stale eeSFL_FileList_* and folder records');
$byPath = [];
foreach ($entries as $entry) {
    $byPath[$entry['file_path']] = $entry;
}
$expectedPath1 = wp_normalize_path($avpvhDir1 . '/13_ams_25_104_F_V_01.pdf');
$expectedPath2 = wp_normalize_path($avpvhDir2 . '/report.pdf');
assertTrue(isset($byPath[$expectedPath1]), 'List 1 PDF resolved');
assertSame(1, $byPath[$expectedPath1]['list_id'], 'List 1 ID preserved');
assertSame('Verslag curator 1', $byPath[$expectedPath1]['display_name'], 'List 1 nice name preserved');
assertTrue(isset($byPath[$expectedPath2]), 'List 2 report.pdf resolved to list-two (not duplicate basename in list 1!)');
assertSame(2, $byPath[$expectedPath2]['list_id'], 'List 2 ID preserved');

$adminUrl2 = $support->buildSimpleFileListAdminUrl($expectedPath2, 2);
assertTrue(strpos($adminUrl2, 'page=avpvh-filelist') !== false, 'Admin URL uses avpvh-filelist slug');
assertTrue(strpos($adminUrl2, 'eeListID=2') !== false, 'Admin URL includes eeListID=2');
assertTrue(strpos($adminUrl2, 'sfl_search_2=report.pdf') !== false, 'Admin URL includes sfl_search_2=report.pdf');

// 3. Nice-name update targets the exact list (even with duplicate basename report.pdf)
$updater = new SimpleFileListNiceNameUpdater($support);
$rows = $support->getFileListOptionRows();
$updatedRow = null;
foreach ($rows as $row) {
    $candidate = $updater->buildUpdatedOption($row, $expectedPath2, 'Updated Report 2');
    if ($candidate !== null) {
        $updatedRow = [$row['option_name'], $candidate];
    }
}
assertTrue($updatedRow !== null, 'Nice-name updater matched list 2 report.pdf');
assertSame('avpvh_flst_files_2', $updatedRow[0], 'Nice-name updater matched avpvh_flst_files_2 specifically');
assertSame('Updated Report 2', $updatedRow[1][0]['FileNiceName'], 'Nice-name updated correctly');

// 4. Remove entry by path works (previously fatal due to missing buildSimpleFileListTargets!)
$removed = $support->removeSimpleFileListEntryByPath($expectedPath2);
assertSame(true, $removed, 'removeSimpleFileListEntryByPath removes matching entry from avpvh_flst_files_2');
assertSame(0, count($GLOBALS['test_options']['avpvh_flst_files_2']), 'avpvh_flst_files_2 emptied after removal');
assertSame(2, count($GLOBALS['test_options']['avpvh_flst_files_1']), 'avpvh_flst_files_1 untouched');

// 5. Merge into overview marks referenced doc as managed_by_simple_file_list + list_id
$hash1 = hash_file('sha256', $expectedPath1);
$overview = [
    $hash1 => [
        'file_hash' => $hash1,
        'display_name' => '13_ams_25_104_F_V_01.pdf',
        'nice_name' => '13_ams_25_104_F_V_01.pdf',
        'managed_by_simple_file_list' => false,
        'simple_file_list_id' => 0,
    ],
];
$support->mergeSimpleFileListOptionEntries($overview, [], static fn() => []);
assertSame(true, $overview[$hash1]['managed_by_simple_file_list'], 'Referenced PDF marked as managed by File List');
assertSame(1, $overview[$hash1]['simple_file_list_id'], 'Overview entry receives list ID 1');
assertSame('Verslag curator 1', $overview[$hash1]['nice_name'], 'Overview entry receives nice name from avpvh_flst_files_1');

// 6. Table rendering: attached to post/page and NOT in File List is marked neutrally, NOT as Error
$table = new ReferencedDocumentListTable();
$attachedNotInFileList = [
    'file_hash' => 'abc123def456',
    'display_name' => '13_ams_25_104_F_V_01.pdf',
    'file_url' => 'https://example.test/wp-content/uploads/2025/06/13_ams_25_104_F_V_01.pdf',
    'file_path' => $expectedPath1,
    'managed_by_simple_file_list' => false,
    'broken_reference' => false,
    'status' => 'Uploaded',
    'status_color' => '#46b450',
    'include_in_store_target' => true,
    'modified_after_upload' => false,
    'operation_status' => '',
    'operation_error' => '',
    'posts' => [['id' => 1861, 'title' => 'Faillissement GLT']],
    'reference_count' => 1,
    'external_reference_count' => 1,
    'mime_type' => 'application/pdf',
];
$nameHtml = $table->renderDisplayNameCell($attachedNotInFileList);
$statusHtml = $table->renderStatusCell($attachedNotInFileList);
$actionsHtml = $table->renderActionsCell($attachedNotInFileList);
$rowHtml = $table->renderRowHtml($attachedNotInFileList);

assertTrue(strpos($nameHtml, 'Not in File List') !== false, 'Name cell marks post/page attachment as Not in File List');
assertTrue(strpos($nameHtml, '#d63638') === false, 'Not in File List marker does not use error red');
assertTrue(strpos($statusHtml, 'Indexed') !== false, 'AI Indexed cell shows Indexed (not Error)');
assertTrue(strpos($statusHtml, 'Error') === false, 'AI Indexed cell does not show Error');
assertTrue(strpos($actionsHtml, 'Missing from') === false, 'Actions cell has no Missing from error text');
assertTrue(strpos($rowHtml, 'data-referenced-document-status="indexed"') !== false, 'Row status filter is indexed, not error');
assertTrue(strpos($rowHtml, 'data-referenced-document-referenced-in="not_in_file_list"') !== false, 'Row scope filter is not_in_file_list');

$brokenRef = array_merge($attachedNotInFileList, ['broken_reference' => true, 'status' => 'Referenced on site, but file is missing', 'status_color' => '#d63638']);
$brokenRowHtml = $table->renderRowHtml($brokenRef);
assertTrue(strpos($brokenRowHtml, 'data-referenced-document-status="error"') !== false, 'Genuinely broken file still reports error');
assertTrue(strpos($table->renderDisplayNameCell($brokenRef), 'Not in File List') === false, 'Broken file does not duplicate Not in File List label');

// Cleanup temp directory
@unlink($avpvhDir1 . '/13_ams_25_104_F_V_01.pdf');
@unlink($avpvhDir1 . '/report.pdf');
@unlink($avpvhDir2 . '/report.pdf');
@unlink($legacyDir . '/legacy_only.pdf');
@rmdir($avpvhDir1);
@rmdir($avpvhDir2);
@rmdir($legacyDir);
@rmdir($uploadsDir);
@rmdir($tempRoot . '/wp-content');
@rmdir($tempRoot);

echo "OK: AV-PvH File List support and legacy fallback validated.\n";
