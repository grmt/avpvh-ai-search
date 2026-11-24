<?php
namespace Geweb\AISearch;

// Exception messages here are plain text for logs and JSON responses; they are
// escaped where they are shown (esc_html in PHP, .text() in JS), not at the throw.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

defined('ABSPATH') || exit;

final class ChatMeilisearchService {
    public const INDEX_NAME = 'rsp_chats';

    private ChatArchiveRepository $repository;

    public function __construct(ChatArchiveRepository $repository) {
        $this->repository = $repository;
    }

    /**
     * @return array<int,array<string,mixed>>|null
     */
    public function search(string $query, int $limit = 100): ?array {
        $response = $this->request(
            'POST',
            '/indexes/' . self::INDEX_NAME . '/search',
            [
                'q' => $query,
                'limit' => $limit,
                'attributesToRetrieve' => ['id', 'path', 'title', 'participants', 'message_count', 'part', 'part_count', 'visibility'],
                'attributesToCrop' => ['content'],
                'cropLength' => 45,
            ],
            false
        );
        if (!is_array($response) || !is_array($response['hits'] ?? null)) {
            return null;
        }
        return array_values(array_map([$this, 'normalizeHit'], $response['hits']));
    }

    /**
     * @return array<string,mixed>
     */
    public function synchronize(): array {
        $documents = $this->repository->getMeilisearchDocuments();
        $this->deleteExistingIndex();
        $upload = $this->request('POST', '/indexes/' . self::INDEX_NAME . '/documents?primaryKey=id', $documents, true);
        $this->waitForTask((int) ($upload['taskUid'] ?? 0));
        $settings = $this->request('PATCH', '/indexes/' . self::INDEX_NAME . '/settings', [
            'searchableAttributes' => ['title', 'participants', 'content', 'path'],
            'displayedAttributes' => ['id', 'path', 'title', 'participants', 'message_count', 'part', 'part_count', 'visibility', 'content'],
            'filterableAttributes' => ['visibility', 'participants'],
            'sortableAttributes' => ['message_count'],
        ], true);
        $this->waitForTask((int) ($settings['taskUid'] ?? 0));
        return ['index' => self::INDEX_NAME, 'documents' => count($documents)];
    }

    private function deleteExistingIndex(): void {
        $response = $this->request('DELETE', '/indexes/' . self::INDEX_NAME, null, true, true);
        $taskUid = is_array($response) ? (int) ($response['taskUid'] ?? 0) : 0;
        if ($taskUid > 0) {
            $this->waitForTask($taskUid);
        }
    }

    private function waitForTask(int $taskUid): void {
        if ($taskUid <= 0) {
            throw new \RuntimeException('Meilisearch did not return a task identifier.');
        }
        for ($attempt = 0; $attempt < 120; ++$attempt) {
            $task = $this->request('GET', '/tasks/' . $taskUid, null, true);
            $status = (string) ($task['status'] ?? '');
            if ($status === 'succeeded') {
                return;
            }
            if ($status === 'failed' || $status === 'canceled') {
                throw new \RuntimeException('Meilisearch task failed: ' . wp_json_encode($task['error'] ?? []));
            }
            usleep(250000);
        }
        throw new \RuntimeException('Timed out waiting for Meilisearch task ' . $taskUid . '.');
    }

    /**
     * @param mixed $payload
     * @return array<string,mixed>|null
     */
    private function request(string $method, string $path, $payload, bool $admin, bool $allowNotFound = false): ?array {
        $key = $admin ? $this->getAdminKey() : $this->getSearchKey();
        $arguments = [
            'method' => $method,
            'timeout' => 180,
            'headers' => ['Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'],
        ];
        if ($payload !== null) {
            $arguments['body'] = wp_json_encode($payload);
        }
        $response = wp_remote_request($this->getUrl() . $path, $arguments);
        if (is_wp_error($response)) {
            throw new \RuntimeException($response->get_error_message());
        }
        $status = wp_remote_retrieve_response_code($response);
        if ($allowNotFound && $status === 404) {
            return null;
        }
        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            throw new \RuntimeException('Meilisearch request failed with HTTP ' . $status . '.');
        }
        return $decoded;
    }

    private function getUrl(): string {
        $settings = get_option('fav_settings', []);
        $url = is_array($settings) ? (string) ($settings['meili_url'] ?? '') : '';
        $url = $url !== '' ? $url : (string) get_option('meili_search_url', '');
        if ($url === '') {
            throw new \RuntimeException('Meilisearch URL is not configured.');
        }
        return untrailingslashit($url);
    }

    private function getSearchKey(): string {
        $key = (string) get_option('meili_search_key', '');
        return $key !== '' ? $key : $this->getAdminKey();
    }

    private function getAdminKey(): string {
        $secretPath = '/run/secrets/meilisearch_master_key';
        $key = is_readable($secretPath) ? trim((string) file_get_contents($secretPath)) : '';
        if ($key === '') {
            $key = (string) get_option('meili_admin_key', '');
        }
        if ($key === '') {
            throw new \RuntimeException('Meilisearch admin key is not configured.');
        }
        return $key;
    }

    /**
     * @param mixed $hit
     * @return array<string,mixed>
     */
    private function normalizeHit($hit): array {
        $item = is_array($hit) ? $hit : [];
        $formatted = is_array($item['_formatted'] ?? null) ? $item['_formatted'] : [];
        return [
            'id' => (string) ($item['id'] ?? ''),
            'path' => (string) ($item['path'] ?? ''),
            'display_title' => (string) ($item['title'] ?? 'Chat'),
            'participants' => array_values((array) ($item['participants'] ?? [])),
            'indexed_messages' => (int) ($item['message_count'] ?? 0),
            'part' => (int) ($item['part'] ?? 1),
            'part_count' => (int) ($item['part_count'] ?? 1),
            'visibility' => 'internal',
            'snippet' => wp_strip_all_tags((string) ($formatted['content'] ?? '')),
        ];
    }
}
