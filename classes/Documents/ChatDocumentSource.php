<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Discovers preprocessed chat Markdown documents stored for Find and View.
 */
final class ChatDocumentSource {
    /**
     * @param array<string,array<string,mixed>> $overview
     * @param array<string,array<string,mixed>> $uploadedByHash
     */
    public function mergeIntoOverview(array &$overview, array $uploadedByHash, callable $buildOverviewEntry): void {
        foreach ($this->getEntries() as $entry) {
            $hash = hash_file('sha256', $entry['file_path']);
            if (!is_string($hash) || $hash === '') {
                continue;
            }
            if (!isset($overview[$hash])) {
                $overview[$hash] = $buildOverviewEntry(
                    $hash,
                    $entry['file_path'],
                    $entry['file_url'],
                    $entry['display_name'],
                    $uploadedByHash[$hash] ?? null,
                    false
                );
            }
            $overview[$hash]['display_name'] = $entry['display_name'];
            $overview[$hash]['nice_name'] = $entry['display_name'];
            $isPrivate = $entry['visibility'] === 'private';
            $overview[$hash]['external_reference_count'] = $isPrivate
                ? 0
                : max(1, (int) ($overview[$hash]['external_reference_count'] ?? 0));
            $overview[$hash]['managed_by_chat_library'] = true;
            $overview[$hash]['chat_visibility'] = $entry['visibility'];
        }
    }

    /**
     * @return array<int,array{file_path:string,file_url:string,display_name:string,visibility:string}>
     */
    public function getEntries(): array {
        try {
            return $this->collectEntries();
        } catch (\Throwable $exception) {
            error_log('geweb-ai-search: could not scan chat document library: ' . $exception->getMessage());
            return [];
        }
    }

    /**
     * @return array<int,array{file_path:string,file_url:string,display_name:string,visibility:string}>
     */
    private function collectEntries(): array {
        $entries = [];
        foreach ((new ChatArchiveRepository())->getDocuments(true) as $document) {
            $entries[] = [
                'file_path' => (string) $document['file_path'],
                'file_url' => '',
                'display_name' => 'Chat — ' . (string) $document['display_title'] . ' — ' . basename((string) $document['path']),
                'visibility' => (string) $document['visibility'],
            ];
        }
        usort($entries, static function (array $left, array $right): int {
            return strcasecmp($left['display_name'], $right['display_name']);
        });
        return $entries;
    }

}
