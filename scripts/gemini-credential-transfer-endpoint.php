<?php
/**
 * Ephemeral endpoint used by copy-gemini-credentials.sh.
 *
 * The deployment script replaces both placeholders before installation.
 */

declare(strict_types=1);

const GEWEB_TRANSFER_TOKEN = '__GEWEB_TRANSFER_TOKEN__';
const GEWEB_TRANSFER_TARGET_URL = '__GEWEB_TRANSFER_TARGET_URL__';

if (strpos(GEWEB_TRANSFER_TOKEN, '__GEWEB_') === 0 || strpos(GEWEB_TRANSFER_TARGET_URL, '__GEWEB_') === 0) {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__, 3) . '/wp-load.php';

use Geweb\AISearch\Encryption;
use Geweb\AISearch\Gemini;
use Geweb\AISearch\UserScope;
use Geweb\AISearch\WorkspaceRegistry;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/**
 * @param array<string,mixed> $payload
 */
function geweb_transfer_reply(array $payload, int $status = 200): void {
    status_header($status);
    echo wp_json_encode($payload);
    exit;
}

function geweb_transfer_is_authorized(): bool {
    $provided = isset($_POST['token']) ? (string) wp_unslash($_POST['token']) : '';
    return hash_equals(GEWEB_TRANSFER_TOKEN, $provided);
}

function geweb_transfer_select_workspace(): string {
    $definitions = (new WorkspaceRegistry())->getWorkspaceDefinitions();
    if (isset($definitions[WorkspaceRegistry::DEFAULT_WORKSPACE_ID])) {
        $workspaceId = WorkspaceRegistry::DEFAULT_WORKSPACE_ID;
    } elseif (count($definitions) === 1) {
        $workspaceId = (string) array_key_first($definitions);
    } else {
        geweb_transfer_reply(['ok' => false, 'error' => 'Workspace is ambiguous.'], 409);
    }

    $_GET['geweb_workspace_id'] = $workspaceId;
    return $workspaceId;
}

/**
 * @return array{status:string,model_count:int}
 */
function geweb_transfer_check_connection(): array {
    $models = (new Gemini())->getModels(true);
    $connection = UserScope::getWorkspaceConfigOption('geweb_aisearch_connection_status', []);

    return [
        'status' => is_array($connection) ? (string) ($connection['status'] ?? '') : '',
        'model_count' => count($models),
    ];
}

function geweb_transfer_restore_key(Encryption $encryption, string $previousKey): void {
    if ($previousKey !== '') {
        $encryption->saveApiKey($previousKey);
        return;
    }

    UserScope::deleteWorkspaceConfigOption('geweb_aisearch_api_key_encrypted');
}

function geweb_transfer_import(): void {
    $workspaceId = geweb_transfer_select_workspace();
    $apiKey = isset($_POST['api_key']) ? trim((string) wp_unslash($_POST['api_key'])) : '';
    if ($apiKey === '' || strlen($apiKey) > 2048) {
        geweb_transfer_reply(['ok' => false, 'error' => 'No valid API key supplied.'], 400);
    }

    $encryption = new Encryption();
    $previousKey = $encryption->getApiKey();
    $saved = $encryption->saveApiKey($apiKey);
    $matches = hash_equals($apiKey, $encryption->getApiKey());
    unset($apiKey);

    if (!$saved && !$matches) {
        geweb_transfer_reply(['ok' => false, 'error' => 'Could not save the API key.'], 500);
    }

    $connection = geweb_transfer_check_connection();
    if (!$matches || $connection['status'] !== 'ok') {
        geweb_transfer_restore_key($encryption, $previousKey);
        geweb_transfer_reply([
            'ok' => false,
            'error' => 'Gemini rejected the copied credential; the previous target key was restored.',
            'gemini_connection' => $connection['status'],
        ], 502);
    }

    geweb_transfer_reply([
        'ok' => true,
        'workspace' => $workspaceId,
        'credential_verified' => true,
        'gemini_connection' => $connection['status'],
        'model_count' => $connection['model_count'],
    ]);
}

function geweb_transfer_send(): void {
    $workspaceId = geweb_transfer_select_workspace();
    $apiKey = (new Encryption())->getApiKey();
    if ($apiKey === '') {
        geweb_transfer_reply(['ok' => false, 'error' => 'Source API key is missing.'], 404);
    }

    $response = wp_remote_post(GEWEB_TRANSFER_TARGET_URL, [
        'timeout' => 45,
        'body' => [
            'token' => GEWEB_TRANSFER_TOKEN,
            'action' => 'import',
            'api_key' => $apiKey,
        ],
    ]);
    unset($apiKey);

    if (is_wp_error($response)) {
        geweb_transfer_reply(['ok' => false, 'error' => 'Target request failed.'], 502);
    }

    $target = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($target)) {
        geweb_transfer_reply(['ok' => false, 'error' => 'Target response was invalid.'], 502);
    }

    geweb_transfer_reply([
        'ok' => (bool) ($target['ok'] ?? false),
        'source_workspace' => $workspaceId,
        'target_workspace' => (string) ($target['workspace'] ?? ''),
        'credential_verified' => (bool) ($target['credential_verified'] ?? false),
        'gemini_connection' => (string) ($target['gemini_connection'] ?? ''),
        'model_count' => (int) ($target['model_count'] ?? 0),
        'error' => (string) ($target['error'] ?? ''),
    ]);
}

if (!geweb_transfer_is_authorized()) {
    geweb_transfer_reply(['ok' => false, 'error' => 'Forbidden.'], 403);
}

$action = isset($_POST['action']) ? (string) wp_unslash($_POST['action']) : '';
if ($action === 'import') {
    geweb_transfer_import();
}
if ($action === 'send') {
    geweb_transfer_send();
}

geweb_transfer_reply(['ok' => false, 'error' => 'Unsupported action.'], 400);
