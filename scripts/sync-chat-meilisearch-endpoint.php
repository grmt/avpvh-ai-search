<?php
/**
 * Ephemeral authenticated endpoint used by sync-chat-meilisearch.sh.
 */

declare(strict_types=1);

const GEWEB_CHAT_MEILI_TOKEN = '__GEWEB_CHAT_MEILI_TOKEN__';

if (strpos(GEWEB_CHAT_MEILI_TOKEN, '__GEWEB_') === 0) {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__, 3) . '/wp-load.php';

use Geweb\AISearch\ChatArchiveRepository;
use Geweb\AISearch\ChatMeilisearchService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$provided = isset($_POST['token']) ? (string) wp_unslash($_POST['token']) : '';
if (!hash_equals(GEWEB_CHAT_MEILI_TOKEN, $provided)) {
    status_header(403);
    echo wp_json_encode(['ok' => false, 'error' => 'Forbidden.']);
    exit;
}

$adminIds = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
$adminId = (int) ($adminIds[0] ?? 0);
if ($adminId <= 0) {
    status_header(409);
    echo wp_json_encode(['ok' => false, 'error' => 'No administrator found.']);
    exit;
}

wp_set_current_user($adminId);
set_time_limit(0);

try {
    $repository = new ChatArchiveRepository();
    $result = (new ChatMeilisearchService($repository))->synchronize();
    echo wp_json_encode(['ok' => true] + $result);
} catch (Throwable $exception) {
    status_header(500);
    echo wp_json_encode(['ok' => false, 'error' => $exception->getMessage()]);
}
