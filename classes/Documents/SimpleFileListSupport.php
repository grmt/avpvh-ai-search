<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * AV-PvH File List (and legacy Simple File List) discovery and metadata helpers.
 */
class SimpleFileListSupport {
    use SimpleFileListSupportHelpersTrait;

    private const AVPVH_FILES_OPTION_PREFIX = 'avpvh_flst_files_';
    private const AVPVH_SETTINGS_OPTION_PREFIX = 'avpvh_flst_settings_';
    private const AVPVH_DEFAULT_DIR = 'wp-content/uploads/avpvh-filelist/';
    private const LEGACY_FILES_OPTION_PREFIX = 'eeSFL_FileList_';
    private const LEGACY_SETTINGS_OPTION_PREFIX = 'eeSFL_Settings_';
    private const LEGACY_DEFAULT_DIR = 'wp-content/uploads/simple-file-list/';

    /** @var array<string,string> */
    private array $fileListDirCache = [];

    private ?bool $usingAvpvhFileList = null;

    /**
     * Determine whether a post is rendering a File List shortcode.
     */
    public function isSimpleFileListPage(\WP_Post $post): bool {
        $content = (string) $post->post_content;
        if (shortcode_exists('avpvh_filelist') && has_shortcode($content, 'avpvh_filelist')) {
            return true;
        }

        return shortcode_exists('eeSFL') && has_shortcode($content, 'eeSFL');
    }

    /**
     * Determine whether AV-PvH File List is active or has stored file-list options.
     */
    public function isUsingAvpvhFileList(): bool {
        if ($this->usingAvpvhFileList !== null) {
            return $this->usingAvpvhFileList;
        }

        if ($this->isAvpvhFileListPluginActive()) {
            $this->usingAvpvhFileList = true;
            return true;
        }

        $this->usingAvpvhFileList = !empty($this->queryFileListOptionRowsByPrefix(self::AVPVH_FILES_OPTION_PREFIX));
        return $this->usingAvpvhFileList;
    }

    /**
     * Read file-list option rows from AV-PvH File List (or legacy Simple File List fallback).
     *
     * @return array<int,array<string,mixed>>
     */
    public function getFileListOptionRows(): array {
        if ($this->isAvpvhFileListPluginActive()) {
            $this->usingAvpvhFileList = true;
            return $this->queryFileListOptionRowsByPrefix(self::AVPVH_FILES_OPTION_PREFIX);
        }

        $avpvhRows = $this->queryFileListOptionRowsByPrefix(self::AVPVH_FILES_OPTION_PREFIX);
        if (!empty($avpvhRows)) {
            $this->usingAvpvhFileList = true;
            return $avpvhRows;
        }

        $this->usingAvpvhFileList = false;
        return $this->queryFileListOptionRowsByPrefix(self::LEGACY_FILES_OPTION_PREFIX);
    }

    /**
     * Expand File List references to include additional files in the same list folder.
     *
     * @param array<int,array<string,string>> $references
     * @return array<int,array<string,string>>
     */
    public function expandSimpleFileListReferences(array $references): array {
        $expanded = [];
        $directories = [];

        foreach ($references as $reference) {
            if (is_array($reference)) {
                $this->collectReferenceForExpansion($reference, $expanded, $directories);
            }
        }

        $niceNameMap = $this->getSimpleFileListNiceNameMap(array_keys($directories));

        foreach ($expanded as $filePath => $reference) {
            if (isset($niceNameMap[$filePath]) && $niceNameMap[$filePath] !== '') {
                $expanded[$filePath]['display_name'] = $niceNameMap[$filePath];
            }
        }

        foreach ($directories as $directory => $data) {
            foreach ($this->getSupportedFilesInDirectory($directory) as $filePath) {
                $this->expandDirectoryFile($filePath, $data, $niceNameMap, $expanded);
            }
        }

        return array_values($expanded);
    }

    /**
     * Merge File List database entries so list-only files appear even without website references.
     *
     * @param array<string,array<string,mixed>> $overview
     * @param array<string,array<string,mixed>> $uploadedByHash
     */
    public function mergeSimpleFileListOptionEntries(array &$overview, array $uploadedByHash, callable $buildOverviewEntry): void {
        foreach ($this->getSimpleFileListEntries() as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $this->mergeEntryIntoOverview($entry, $overview, $uploadedByHash, $buildOverviewEntry);
        }
    }

    /**
     * Read File List entries from avpvh_flst_files_* (or legacy eeSFL_FileList_*) options.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getSimpleFileListEntries(): array {
        $rows = $this->getFileListOptionRows();
        if (empty($rows)) {
            error_log('geweb-ai-search: File List entry lookup found no file-list option rows.');
            return [];
        }

        $entries = [];
        foreach ($rows as $row) {
            $this->processSimpleFileListEntryRow($row, $entries);
        }

        error_log('geweb-ai-search: File List entry lookup resolved ' . count($entries) . ' file(s).');

        return array_values($entries);
    }

    public function buildSimpleFileListAdminUrl(string $filePath, int $listId = 1): string {
        $searchTerm = basename(wp_normalize_path($filePath));
        $listId = max(1, $listId);

        if ($this->isUsingAvpvhFileList()) {
            $slug = defined('AVPVH_FLST_SLUG') ? (string) AVPVH_FLST_SLUG : 'avpvh-filelist';
            $args = [
                'page' => $slug,
                'tab' => 'list',
                'eeListID' => $listId,
            ];
            if ($searchTerm !== '') {
                $args['sfl_search_' . $listId] = $searchTerm;
            }

            return add_query_arg($args, admin_url('admin.php'));
        }

        $baseUrl = admin_url('admin.php?page=ee-simple-file-list');
        if ($searchTerm === '') {
            return $baseUrl;
        }

        return add_query_arg([
            's' => $searchTerm,
            'eeFileSearch' => $searchTerm,
        ], $baseUrl);
    }

    public function removeSimpleFileListEntryByPath(string $filePath): bool {
        $normalizedPath = wp_normalize_path($filePath);
        $targets = $normalizedPath !== '' && is_readable($normalizedPath)
            ? $this->buildSimpleFileListTargets([$normalizedPath])
            : [];
        if (empty($targets)) {
            return false;
        }

        $rows = $this->getFileListOptionRows();
        if (empty($rows)) {
            return false;
        }

        $removedAny = false;
        foreach ($rows as $row) {
            $this->processRemoveRow($row, $normalizedPath, $targets, $removedAny);
        }

        return $removedAny;
    }

    /**
     * @param array<int,string> $directories
     * @return array<string,string>
     */
    public function getSimpleFileListNiceNameMap(array $directories): array {
        global $wpdb;

        $targets = $this->buildDirectoryFileTargets($directories);
        if (empty($targets)) {
            return [];
        }

        $niceNames = $this->getSimpleFileListNiceNamesFromFileListOptions($targets);

        foreach ($this->getMetadataOptionRows() as $row) {
            if (!is_array($row) || !isset($row['option_value'])) {
                continue;
            }
            $this->scanSimpleFileListMetadata(maybe_unserialize($row['option_value']), $targets, $niceNames);
        }

        foreach ($this->getSflCandidateTableNames() as $tableName) {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i', $tableName), ARRAY_A);
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $this->scanSimpleFileListMetadata($row, $targets, $niceNames);
                }
            }
        }

        return $niceNames;
    }

    /**
     * @param array<int,string> $directories
     * @return array<string,int>
     */
    public function getSimpleFileListModifiedMap(array $directories): array {
        global $wpdb;

        $targets = $this->buildDirectoryFileTargets($directories);
        if (empty($targets)) {
            return [];
        }

        $modifiedMap = [];

        foreach ($this->getMetadataOptionRows() as $row) {
            if (!is_array($row) || !isset($row['option_value'])) {
                continue;
            }
            $this->scanSimpleFileListModifiedMetadata(maybe_unserialize($row['option_value']), $targets, $modifiedMap);
        }

        foreach ($this->getSflCandidateTableNames() as $tableName) {
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i', $tableName), ARRAY_A);
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $this->scanSimpleFileListModifiedMetadata($row, $targets, $modifiedMap);
                }
            }
        }

        return $modifiedMap;
    }

    /**
     * @param array<string,array<string,string>> $targets
     * @return array<string,string>
     */
    public function getSimpleFileListNiceNamesFromFileListOptions(array $targets): array {
        $rows = $this->getFileListOptionRows();
        if (empty($rows)) {
            error_log('geweb-ai-search: File List nice-name lookup found no file-list option rows.');
            return [];
        }

        error_log('geweb-ai-search: File List nice-name lookup scanning ' . count($rows) . ' option row(s) for ' . count($targets) . ' target file(s).');
        $niceNames = [];
        foreach ($rows as $row) {
            $this->processNiceNameFileListRow($row, $targets, $niceNames);
        }

        error_log('geweb-ai-search: File List nice-name lookup resolved ' . count($niceNames) . ' file(s).');
        return $niceNames;
    }

    /**
     * @param mixed $value
     * @param array<string,array<string,string>> $targets
     * @param array<string,string> $niceNames
     */
    public function scanSimpleFileListMetadata($value, array $targets, array &$niceNames): void {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (!is_array($value)) {
            return;
        }

        $match = $this->extractSimpleFileListRecordMatch($value, $targets);
        if ($match !== null) {
            $filePath = $match['file_path'];
            $niceName = $match['nice_name'];
            if ($filePath !== '' && $niceName !== '' && !isset($niceNames[$filePath])) {
                $niceNames[$filePath] = $niceName;
            }
        }

        foreach ($value as $nested) {
            if (is_array($nested) || is_object($nested)) {
                $this->scanSimpleFileListMetadata($nested, $targets, $niceNames);
            }
        }
    }

    /**
     * @param mixed $value
     * @param array<string,array<string,string>> $targets
     * @param array<string,int> $modifiedMap
     */
    public function scanSimpleFileListModifiedMetadata($value, array $targets, array &$modifiedMap): void {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (!is_array($value)) {
            return;
        }

        $match = $this->extractSimpleFileListRecordModifiedMatch($value, $targets);
        if ($match !== null) {
            $filePath = $match['file_path'];
            $modifiedAt = (int) $match['modified_at'];
            if ($filePath !== '' && $modifiedAt > 0 && (!isset($modifiedMap[$filePath]) || $modifiedAt > $modifiedMap[$filePath])) {
                $modifiedMap[$filePath] = $modifiedAt;
            }
        }

        foreach ($value as $nested) {
            if (is_array($nested) || is_object($nested)) {
                $this->scanSimpleFileListModifiedMetadata($nested, $targets, $modifiedMap);
            }
        }
    }

    /**
     * @param array<string,mixed> $record
     * @param array<string,array<string,string>> $targets
     * @return array{file_path:string,modified_at:int}|null
     */
    public function extractSimpleFileListRecordModifiedMatch(array $record, array $targets): ?array {
        $stringFields = $this->buildStringFields($record);
        if (empty($stringFields)) {
            return null;
        }

        foreach ($targets as $filePath => $target) {
            if (!$this->isTargetMatchedByFields($stringFields, $target['basename'], $target['relative_path'])) {
                continue;
            }
            $modifiedAt = $this->findModifiedTimestampFromFields($stringFields);
            if ($modifiedAt !== null) {
                return ['file_path' => $filePath, 'modified_at' => $modifiedAt];
            }
        }

        return null;
    }
}
