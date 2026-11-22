<?php
declare(strict_types=1);

/**
 * Validates AdminSetupChecklist item evaluation and rendered links.
 */

define('ABSPATH', __DIR__ . '/../');

function __(string $text, string $domain = 'default'): string {
    return $text;
}
function esc_html__(string $text, string $domain = 'default'): string {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
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
function admin_url(string $path = ''): string {
    return 'https://kb.example.test/wp-admin/' . ltrim($path, '/');
}
function add_query_arg(array $args, string $url): string {
    $sep = strpos($url, '?') === false ? '?' : '&';
    return $url . $sep . http_build_query($args);
}

require_once __DIR__ . '/../classes/Admin/AdminSetupChecklist.php';

use Geweb\AISearch\AdminSetupChecklist;

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

// 1. Fresh/incomplete KB-like state (API key + store valid, missing frontend sharing, post types, referenced docs)
$kbItems = AdminSetupChecklist::buildFromContext([
    'activeWorkspaceId' => 'default',
    'connectionStatus' => ['status' => 'ok'],
    'hasValidSavedApiKey' => true,
    'storeEnabled' => true,
    'shareGeminiConfigWithFrontend' => false,
    'postTypes' => [],
    'includeReferencedDocuments' => false,
]);
$incomplete = AdminSetupChecklist::getIncompleteItems($kbItems);
$missingRequired = AdminSetupChecklist::getIncompleteItems($kbItems, true);
assertSame(3, count($incomplete), 'KB state has 3 incomplete checklist items');
assertSame(2, count($missingRequired), 'KB state has 2 missing required items (frontend sharing + post types)');

ob_start();
AdminSetupChecklist::renderSettingsBanner($kbItems);
$bannerHtml = (string) ob_get_clean();
assertTrue(strpos($bannerHtml, '2 of 5 configured') !== false, 'Banner shows 2 of 5 configured');
assertTrue(strpos($bannerHtml, '#geweb-setting-frontend-sharing') !== false, 'Banner links directly to frontend sharing checkbox');
assertTrue(strpos($bannerHtml, '#geweb-setting-post-types') !== false, 'Banner links directly to post types section');
assertTrue(strpos($bannerHtml, '#geweb-setting-referenced-documents') !== false, 'Banner links directly to referenced documents checkbox');
assertTrue(strpos($bannerHtml, 'geweb_workspace_id=default') !== false, 'Banner preserves workspace ID in links');

// 2. Fully configured state renders nothing
$completeItems = AdminSetupChecklist::buildFromContext([
    'activeWorkspaceId' => 'default',
    'connectionStatus' => ['status' => 'ok'],
    'hasValidSavedApiKey' => true,
    'storeEnabled' => true,
    'shareGeminiConfigWithFrontend' => true,
    'postTypes' => ['page', 'post'],
    'includeReferencedDocuments' => true,
]);
assertSame(0, count(AdminSetupChecklist::getIncompleteItems($completeItems)), 'Complete state has 0 incomplete items');
ob_start();
AdminSetupChecklist::renderSettingsBanner($completeItems);
assertSame('', (string) ob_get_clean(), 'Complete state renders no banner');

// 3. Missing API key links store item to API key anchor
$emptyItems = AdminSetupChecklist::buildFromContext([
    'activeWorkspaceId' => '',
    'connectionStatus' => ['status' => 'missing'],
    'hasValidSavedApiKey' => false,
    'storeEnabled' => false,
    'shareGeminiConfigWithFrontend' => false,
    'postTypes' => [],
    'includeReferencedDocuments' => false,
]);
assertSame(4, count(AdminSetupChecklist::getIncompleteItems($emptyItems, true)), 'Empty state has 4 required items');
assertTrue(strpos($emptyItems[1]['url'], '#geweb-setting-api-key') !== false, 'Store item links to API key when key missing');

echo "OK: AdminSetupChecklist checks passed.\n";
