<?php
/**
 * Ephemeral endpoint used by retry-failed-jpgs.sh.
 *
 * The deployment script replaces the token placeholder before installation.
 */

declare(strict_types=1);

const GEWEB_JPG_RETRY_TOKEN = '__GEWEB_JPG_RETRY_TOKEN__';

if (strpos(GEWEB_JPG_RETRY_TOKEN, '__GEWEB_') === 0) {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__, 3) . '/wp-load.php';

use Geweb\AISearch\DocumentStore;
use Geweb\AISearch\ImageOcrService;
use Geweb\AISearch\ReferencedDocumentManager;
use Geweb\AISearch\WorkspaceRegistry;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/**
 * @param array<string,mixed> $payload
 */
function geweb_jpg_retry_reply(array $payload, int $status = 200): void {
    status_header($status);
    echo wp_json_encode($payload);
    exit;
}

function geweb_jpg_retry_is_authorized(): bool {
    $provided = isset($_POST['token']) ? (string) wp_unslash($_POST['token']) : '';
    return hash_equals(GEWEB_JPG_RETRY_TOKEN, $provided);
}

function geweb_jpg_retry_select_admin(): int {
    $adminIds = get_users([
        'role' => 'administrator',
        'number' => 1,
        'fields' => 'ID',
    ]);
    $adminId = isset($adminIds[0]) ? (int) $adminIds[0] : 0;
    if ($adminId <= 0) {
        geweb_jpg_retry_reply(['ok' => false, 'error' => 'No administrator account found.'], 409);
    }

    wp_set_current_user($adminId);
    return $adminId;
}

function geweb_jpg_retry_select_workspace(): string {
    $definitions = (new WorkspaceRegistry())->getWorkspaceDefinitions();
    if (isset($definitions[WorkspaceRegistry::DEFAULT_WORKSPACE_ID])) {
        $workspaceId = WorkspaceRegistry::DEFAULT_WORKSPACE_ID;
    } elseif (count($definitions) === 1) {
        $workspaceId = (string) array_key_first($definitions);
    } else {
        geweb_jpg_retry_reply(['ok' => false, 'error' => 'Workspace is ambiguous.'], 409);
    }

    $_GET['geweb_workspace_id'] = $workspaceId;
    return $workspaceId;
}

/**
 * @param array<string,mixed> $item
 */
function geweb_jpg_retry_is_candidate(array $item): bool {
    $mimeType = strtolower(trim((string) ($item['mime_type'] ?? '')));
    $filePath = (string) ($item['file_path'] ?? '');
    $displayName = (string) ($item['display_name'] ?? basename($filePath));
    $extension = strtolower((string) pathinfo($displayName, PATHINFO_EXTENSION));
    $isSupportedImage = in_array($mimeType, ['image/jpeg', 'image/png'], true)
        || in_array($extension, ['jpg', 'jpeg', 'png'], true);
    $isUploaded = strpos((string) ($item['status'] ?? ''), 'Uploaded') === 0;
    $isIncluded = (bool) ($item['include_in_store_target'] ?? false);

    return $isSupportedImage && !$isUploaded && $isIncluded && $filePath !== '' && is_readable($filePath);
}

/**
 * @param array<string,mixed> $item
 * @return array<string,string>
 */
function geweb_jpg_retry_describe_item(array $item, string $result): array {
    $filePath = (string) ($item['file_path'] ?? '');
    $displayName = trim((string) ($item['display_name'] ?? ''));

    return [
        'file' => $displayName !== '' ? $displayName : basename($filePath),
        'result' => $result,
    ];
}

/**
 * @param array<string,mixed> $item
 * @return array<string,string>
 */
function geweb_jpg_retry_upload_item(ReferencedDocumentManager $manager, array $item): array {
    $fileHash = sanitize_text_field((string) ($item['file_hash'] ?? ''));
    if ($fileHash === '') {
        return geweb_jpg_retry_describe_item($item, 'failed: missing file hash');
    }

    $manager->saveReferencedDocumentImageProcessingMode($fileHash, ImageOcrService::MODE_OCR);
    $manager->saveReferencedDocumentSelectionTarget($fileHash, true);
    $manager->saveReferencedDocumentOperationStatus($fileHash, 'uploading');
    $uploaded = $manager->uploadReferencedDocumentByHash($fileHash);
    $manager->saveReferencedDocumentOperationStatus(
        $fileHash,
        $uploaded ? 'indexed' : 'error',
        $uploaded ? '' : 'OCR conversion or upload failed.'
    );

    return geweb_jpg_retry_describe_item($item, $uploaded ? 'uploaded via OCR Markdown' : 'failed');
}

/**
 * @param array<int,array<string,mixed>> $items
 * @return array<int,array<string,string>>
 */
function geweb_jpg_retry_process(array $items, bool $dryRun, int $limit = 0): array {
    $manager = new ReferencedDocumentManager(new DocumentStore());
    $results = [];
    foreach ($items as $item) {
        if (!is_array($item) || !geweb_jpg_retry_is_candidate($item)) {
            continue;
        }

        $results[] = $dryRun
            ? geweb_jpg_retry_describe_item($item, 'would upload via OCR Markdown')
            : geweb_jpg_retry_upload_item($manager, $item);
        if ($limit > 0 && count($results) >= $limit) {
            break;
        }
    }

    return $results;
}

if (!geweb_jpg_retry_is_authorized()) {
    geweb_jpg_retry_reply(['ok' => false, 'error' => 'Forbidden.'], 403);
}

set_time_limit(0);
geweb_jpg_retry_select_admin();
$workspaceId = geweb_jpg_retry_select_workspace();
$dryRun = !empty($_POST['dry_run']);
$items = (new DocumentStore())->getReferencedDocumentOverview(true);
$results = geweb_jpg_retry_process($items, $dryRun, $dryRun ? 0 : 1);
$failed = count(array_filter($results, static function (array $result): bool {
    return $result['result'] === 'failed' || strpos($result['result'], 'failed:') === 0;
}));

geweb_jpg_retry_reply([
    'ok' => $failed === 0,
    'dry_run' => $dryRun,
    'workspace' => $workspaceId,
    'matched' => count($results),
    'succeeded' => $dryRun ? 0 : count($results) - $failed,
    'failed' => $failed,
    'files' => $results,
]);
