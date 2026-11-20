<?php
/**
 * Token-protected endpoint used by sync-chat-documents.sh.
 */

declare(strict_types=1);

const GEWEB_CHAT_SYNC_TOKEN = '__GEWEB_CHAT_SYNC_TOKEN__';

if (strpos(GEWEB_CHAT_SYNC_TOKEN, '__GEWEB_') === 0) {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__, 3) . '/wp-load.php';

use Geweb\AISearch\DocumentStore;
use Geweb\AISearch\ReferencedDocumentManager;
use Geweb\AISearch\WorkspaceRegistry;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/**
 * @param array<string,mixed> $payload
 */
function geweb_chat_sync_reply(array $payload, int $status = 200): void {
    status_header($status);
    echo wp_json_encode($payload);
    exit;
}

function geweb_chat_sync_authorize(): void {
    $provided = isset($_POST['token']) ? (string) wp_unslash($_POST['token']) : '';
    if (!hash_equals(GEWEB_CHAT_SYNC_TOKEN, $provided)) {
        geweb_chat_sync_reply(['ok' => false, 'error' => 'Forbidden.'], 403);
    }
}

function geweb_chat_sync_select_context(): void {
    $adminIds = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
    $adminId = isset($adminIds[0]) ? (int) $adminIds[0] : 0;
    if ($adminId <= 0) {
        geweb_chat_sync_reply(['ok' => false, 'error' => 'No administrator account found.'], 409);
    }
    wp_set_current_user($adminId);
    $definitions = (new WorkspaceRegistry())->getWorkspaceDefinitions();
    if (!isset($definitions[WorkspaceRegistry::DEFAULT_WORKSPACE_ID])) {
        geweb_chat_sync_reply(['ok' => false, 'error' => 'Default workspace not found.'], 409);
    }
    $_GET['geweb_workspace_id'] = WorkspaceRegistry::DEFAULT_WORKSPACE_ID;
}

/**
 * @param array<int,array<string,mixed>> $overview
 * @return array<int,array<string,mixed>>
 */
function geweb_chat_sync_items(array $overview): array {
    return array_values(array_filter($overview, static function ($item): bool {
        return is_array($item)
            && !empty($item['managed_by_chat_library'])
            && ($item['chat_visibility'] ?? 'internal') !== 'private';
    }));
}

function geweb_chat_sync_is_uploaded(array $item): bool {
    return strpos((string) ($item['status'] ?? ''), 'Uploaded') === 0;
}

/**
 * @param array<int,array<string,mixed>> $overview
 */
function geweb_chat_sync_exclude_monolith(ReferencedDocumentManager $manager, array $overview): void {
    foreach ($overview as $item) {
        if (!is_array($item) || basename((string) ($item['file_path'] ?? '')) !== 'all_chats.txt') {
            continue;
        }
        $hash = (string) ($item['file_hash'] ?? '');
        if ($hash === '') {
            return;
        }
        $manager->saveReferencedDocumentSelectionTarget($hash, false);
        if (geweb_chat_sync_is_uploaded($item)) {
            $manager->removeReferencedDocumentByHash($hash);
        }
        return;
    }
}

/**
 * @return array{file:string,result:string}
 */
function geweb_chat_sync_upload(ReferencedDocumentManager $manager, array $item): array {
    $hash = (string) ($item['file_hash'] ?? '');
    $file = (string) ($item['display_name'] ?? basename((string) ($item['file_path'] ?? '')));
    if ($hash === '') {
        return ['file' => $file, 'result' => 'failed: missing hash'];
    }
    $manager->saveReferencedDocumentSelectionTarget($hash, true);
    $manager->saveReferencedDocumentOperationStatus($hash, 'uploading');
    $uploaded = $manager->uploadReferencedDocumentByHash($hash);
    $manager->saveReferencedDocumentOperationStatus(
        $hash,
        $uploaded ? 'indexed' : 'error',
        $uploaded ? '' : 'Upload failed.'
    );
    return ['file' => $file, 'result' => $uploaded ? 'uploaded' : 'failed'];
}

geweb_chat_sync_authorize();
geweb_chat_sync_select_context();
set_time_limit(0);

$limit = max(1, min(5, isset($_POST['limit']) ? (int) $_POST['limit'] : 3));
$store = new DocumentStore();
$manager = new ReferencedDocumentManager($store);
$overview = $store->getReferencedDocumentOverview(true);
geweb_chat_sync_exclude_monolith($manager, $overview);
$chatItems = geweb_chat_sync_items($overview);
$pending = array_values(array_filter($chatItems, static function (array $item): bool {
    return !geweb_chat_sync_is_uploaded($item);
}));
$results = [];
foreach (array_slice($pending, 0, $limit) as $item) {
    $results[] = geweb_chat_sync_upload($manager, $item);
}

$failed = count(array_filter($results, static function (array $result): bool {
    return $result['result'] !== 'uploaded';
}));
geweb_chat_sync_reply([
    'ok' => $failed === 0,
    'total' => count($chatItems),
    'processed' => count($results),
    'remaining' => max(0, count($pending) - count($results)),
    'failed' => $failed,
    'results' => $results,
], $failed === 0 ? 200 : 500);
