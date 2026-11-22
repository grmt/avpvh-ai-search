<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

final class ChatArchiveRepository {
    private const RELATIVE_ROOT = 'private/scans/chats';

    public function isAvailable(): bool {
        return is_readable($this->getRoot() . '/manifest.json');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getDocuments(bool $includePrivate = false): array {
        $documents = [];
        foreach ((array) ($this->getManifest()['files'] ?? []) as $item) {
            if (!is_array($item) || (!$includePrivate && $this->isPrivate($item))) {
                continue;
            }
            $normalized = $this->normalizeDocument($item);
            if ($normalized !== null) {
                $documents[] = $normalized;
            }
        }
        return $documents;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getDocument(string $relativePath, bool $includePrivate = false): ?array {
        foreach ($this->getDocuments($includePrivate) as $document) {
            if (hash_equals((string) $document['path'], $relativePath)) {
                return $document;
            }
        }
        return null;
    }

    public function readDocument(array $document): string {
        $filePath = (string) ($document['file_path'] ?? '');
        $content = $filePath !== '' && is_readable($filePath) ? file_get_contents($filePath) : false;
        return is_string($content) ? $content : '';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function searchLocal(string $query, bool $includePrivate, int $limit = 100): array {
        $results = [];
        foreach ($this->getDocuments($includePrivate) as $document) {
            $match = $this->matchDocument($document, $query);
            if ($match !== null) {
                $results[] = $match;
            }
            if (count($results) >= $limit) {
                break;
            }
        }
        return $results;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getMeilisearchDocuments(): array {
        $documents = [];
        foreach ($this->getDocuments(false) as $document) {
            $documents[] = [
                'id' => (string) $document['id'],
                'path' => (string) $document['path'],
                'title' => (string) $document['display_title'],
                'participants' => (array) $document['participants'],
                'message_count' => (int) $document['indexed_messages'],
                'part' => (int) $document['part'],
                'part_count' => (int) $document['part_count'],
                'visibility' => 'internal',
                'content' => $this->withoutFrontmatter($this->readDocument($document)),
            ];
        }
        return $documents;
    }

    private function getRoot(): string {
        $uploads = wp_get_upload_dir();
        return wp_normalize_path(trailingslashit((string) ($uploads['basedir'] ?? '')) . self::RELATIVE_ROOT);
    }

    /**
     * @return array<string,mixed>
     */
    private function getManifest(): array {
        static $manifest = null;
        if (is_array($manifest)) {
            return $manifest;
        }
        $manifestPath = $this->getRoot() . '/manifest.json';
        if (!is_file($manifestPath) || !is_readable($manifestPath)) {
            $manifest = [];
            return $manifest;
        }
        $json = file_get_contents($manifestPath);
        $decoded = is_string($json) ? json_decode($json, true) : null;
        $manifest = is_array($decoded) ? $decoded : [];
        return $manifest;
    }

    private function resolveFilePath(string $relativePath): string {
        $root = realpath($this->getRoot());
        $path = realpath($this->getRoot() . '/' . ltrim($relativePath, '/'));
        if (!is_string($root) || !is_string($path) || strpos($path, $root . DIRECTORY_SEPARATOR) !== 0) {
            return '';
        }
        return is_file($path) && is_readable($path) ? wp_normalize_path($path) : '';
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>|null
     */
    private function normalizeDocument(array $item): ?array {
        $relativePath = ltrim((string) ($item['path'] ?? ''), '/');
        $filePath = $this->resolveFilePath($relativePath);
        if ($relativePath === '' || $filePath === '') {
            return null;
        }
        return [
            'id' => hash('sha256', $relativePath),
            'path' => $relativePath,
            'file_path' => $filePath,
            'title' => (string) ($item['title'] ?? ''),
            'display_title' => (string) ($item['display_title'] ?? $item['title'] ?? 'Chat'),
            'participants' => array_values(array_map('strval', (array) ($item['participants'] ?? []))),
            'indexed_messages' => (int) ($item['indexed_messages'] ?? 0),
            'part' => (int) ($item['part'] ?? 1),
            'part_count' => (int) ($item['part_count'] ?? 1),
            'visibility' => $this->isPrivate($item) ? 'private' : 'internal',
        ];
    }

    /**
     * @param array<string,mixed> $item
     */
    private function isPrivate(array $item): bool {
        return ($item['visibility'] ?? 'internal') === 'private';
    }

    /**
     * @param array<string,mixed> $document
     * @return array<string,mixed>|null
     */
    private function matchDocument(array $document, string $query): ?array {
        $content = $this->readDocument($document);
        $metadata = implode(' ', [
            (string) $document['display_title'],
            implode(' ', (array) $document['participants']),
            (string) $document['path'],
            (string) $document['visibility'],
        ]);
        $contentOffset = stripos($content, $query);
        if (stripos($metadata, $query) === false && $contentOffset === false) {
            return null;
        }
        $document['snippet'] = $contentOffset === false
            ? 'Gevonden in titel, deelnemer of bestandsnaam.'
            : $this->snippet($content, (int) $contentOffset, strlen($query));
        unset($document['file_path']);
        return $document;
    }

    private function snippet(string $content, int $offset, int $queryLength): string {
        $start = max(0, $offset - 100);
        $length = max(240, $queryLength + 200);
        $snippet = preg_replace('/\s+/u', ' ', substr($content, $start, $length)) ?? '';
        return ($start > 0 ? '…' : '') . trim($snippet) . ($start + $length < strlen($content) ? '…' : '');
    }

    private function withoutFrontmatter(string $markdown): string {
        return preg_replace('/\A---\s*\R.*?\R---\s*\R/s', '', $markdown) ?? $markdown;
    }
}
