<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

trait SimpleFileListSupportHelpersTrait {
    private function isAvpvhFileListPluginActive(): bool {
        return defined('AVPVH_FLST_VERSION')
            || defined('AVPVH_FLST_SLUG')
            || shortcode_exists('avpvh_filelist');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function queryFileListOptionRowsByPrefix(string $prefix): array {
        global $wpdb;

        $like = $wpdb->esc_like($prefix) . '%';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                $like
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function getMetadataOptionRows(): array {
        global $wpdb;

        if ($this->isUsingAvpvhFileList()) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $wpdb->esc_like('avpvh_flst_') . '%'
                ),
                ARRAY_A
            );
            return is_array($rows) ? $rows : [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like('eeSFL') . '%',
                '%' . $wpdb->esc_like('simple_file_list') . '%'
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    public function extractListIdFromOptionName(string $optionName): int {
        if (preg_match('/(?:avpvh_flst_files_|eeSFL_FileList_)(\d+)$/', $optionName, $matches)) {
            return max(1, (int) $matches[1]);
        }

        return 1;
    }

    public function resolveFileListDirForOption(string $optionName): string {
        if (isset($this->fileListDirCache[$optionName])) {
            return $this->fileListDirCache[$optionName];
        }

        $listId = $this->extractListIdFromOptionName($optionName);
        $isAvpvhOption = strpos($optionName, self::AVPVH_FILES_OPTION_PREFIX) === 0;
        $settingsKey = ($isAvpvhOption ? self::AVPVH_SETTINGS_OPTION_PREFIX : self::LEGACY_SETTINGS_OPTION_PREFIX) . $listId;
        $defaultDir = $isAvpvhOption ? self::AVPVH_DEFAULT_DIR : self::LEGACY_DEFAULT_DIR;

        $settings = get_option($settingsKey);
        $dir = is_array($settings) && !empty($settings['FileListDir'])
            ? (string) $settings['FileListDir']
            : $defaultDir;

        $this->fileListDirCache[$optionName] = $dir;
        return $dir;
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>|null
     */
    private function buildEntryFromRecord(array $record, int $listId = 1, string $fileListDir = ''): ?array {
        if (strtolower((string) ($record['FileExt'] ?? '')) === 'folder') {
            return null;
        }
        $filePathValue = isset($record['FilePath']) ? trim((string) $record['FilePath']) : '';
        if ($filePathValue === '') {
            return null;
        }
        $resolved = $this->resolveSimpleFileListRecordPath($filePathValue, $fileListDir);
        if ($resolved === null) {
            return null;
        }
        $niceName = isset($record['FileNiceName']) ? trim((string) $record['FileNiceName']) : '';

        return [
            'file_path' => $resolved['file_path'],
            'file_url' => $resolved['file_url'],
            'display_name' => $niceName !== '' ? $niceName : $this->prettifyFileName(basename($resolved['file_path'])),
            'list_id' => max(1, $listId),
        ];
    }

    /**
     * @return array{file_path:string,file_url:string}|null
     */
    public function resolveSimpleFileListRecordPath(string $filePathValue, string $fileListDir = ''): ?array {
        $uploads = wp_get_upload_dir();
        $baseDir = wp_normalize_path((string) ($uploads['basedir'] ?? ''));
        $baseUrl = (string) ($uploads['baseurl'] ?? '');
        if ($baseDir === '' || $baseUrl === '') {
            return null;
        }

        $normalizedValue = wp_normalize_path(ltrim(trim($filePathValue), '/'));
        if ($normalizedValue === '' || strpos($normalizedValue, '..') !== false) {
            return null;
        }

        $candidates = $this->buildRecordPathCandidates($normalizedValue, $fileListDir, $baseDir);
        foreach (array_unique($candidates) as $candidate) {
            $resolved = $this->validateResolvedCandidate($candidate, $baseDir, $baseUrl);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function buildRecordPathCandidates(string $normalizedValue, string $fileListDir, string $baseDir): array {
        $candidates = [];
        $normalizedListDir = trim(wp_normalize_path($fileListDir), '/');
        if ($normalizedListDir !== '' && strpos($normalizedListDir, '..') === false) {
            if (defined('ABSPATH') && ABSPATH !== '') {
                $candidates[] = wp_normalize_path(trailingslashit((string) ABSPATH) . $normalizedListDir . '/' . $normalizedValue);
            }
            if (preg_match('#(?:^|/)uploads/(.+)$#', $normalizedListDir, $matches)) {
                $candidates[] = wp_normalize_path(trailingslashit($baseDir) . trim($matches[1], '/') . '/' . $normalizedValue);
            }
        }

        $candidates[] = wp_normalize_path(trailingslashit($baseDir) . $normalizedValue);

        $baseName = basename($normalizedValue);
        if ($baseName !== '') {
            $searched = $this->findFileInUploadsByBasename($baseName, $baseDir);
            if ($searched !== null) {
                $candidates[] = $searched;
            }
        }

        return $candidates;
    }

    /**
     * @return array{file_path:string,file_url:string}|null
     */
    private function validateResolvedCandidate(string $candidate, string $baseDir, string $baseUrl): ?array {
        if ($candidate === '' || !is_file($candidate) || !is_readable($candidate)) {
            return null;
        }

        $uploadsPrefix = trailingslashit($baseDir);
        if (strpos($candidate, $uploadsPrefix) === 0) {
            $relativePath = ltrim(substr($candidate, strlen($baseDir)), '/');
            return [
                'file_path' => $candidate,
                'file_url' => trailingslashit($baseUrl) . str_replace('%2F', '/', rawurlencode($relativePath)),
            ];
        }

        if (defined('ABSPATH') && ABSPATH !== '') {
            $rootPrefix = trailingslashit(wp_normalize_path((string) ABSPATH));
            if (strpos($candidate, $rootPrefix) === 0) {
                $relativeToRoot = ltrim(substr($candidate, strlen($rootPrefix)), '/');
                return [
                    'file_path' => $candidate,
                    'file_url' => home_url('/' . str_replace('%2F', '/', rawurlencode($relativeToRoot))),
                ];
            }
        }

        return null;
    }

    public function findFileInUploadsByBasename(string $baseName, string $baseDir): ?string {
        static $indexedFilesByDir = [];
        if (!array_key_exists($baseDir, $indexedFilesByDir)) {
            $indexedFilesByDir[$baseDir] = $this->buildUploadFileIndex($baseDir);
        }
        $indexedFiles = $indexedFilesByDir[$baseDir];
        if (!is_array($indexedFiles)) {
            return null;
        }

        return isset($indexedFiles[$baseName]) ? (string) $indexedFiles[$baseName][0] : null;
    }

    /**
     * @return array<string,array<int,string>>|null
     */
    private function buildUploadFileIndex(string $baseDir): ?array {
        if (!is_dir($baseDir) || !is_readable($baseDir)) {
            return [];
        }
        try {
            $index = [];
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $fileInfo) {
                if ($fileInfo instanceof \SplFileInfo && $fileInfo->isFile()) {
                    $index[$fileInfo->getBasename()][] = wp_normalize_path($fileInfo->getPathname());
                }
            }
            return $index;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param array<string,mixed> $record
     * @param array<string,array<string,string>> $targets
     * @return array{file_path:string,nice_name:string}|null
     */
    public function extractSimpleFileListRecordMatch(array $record, array $targets): ?array {
        $stringFields = $this->buildStringFields($record);
        if (empty($stringFields)) {
            return null;
        }
        foreach ($targets as $filePath => $target) {
            if (!$this->isTargetMatchedByFields($stringFields, $target['basename'], $target['relative_path'])) {
                continue;
            }
            $niceName = $this->findNiceNameFromMatchedFields($stringFields, $target['basename']);
            if ($niceName !== null) {
                return ['file_path' => $filePath, 'nice_name' => $niceName];
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $niceNameMap
     * @param array<string,array<string,string>> $expanded
     */
    private function expandDirectoryFile(string $filePath, array $data, array $niceNameMap, array &$expanded): void {
        $baseName = basename($filePath);
        $dirUrl = isset($data['dir_url']) ? (string) $data['dir_url'] : '';
        $fileUrl = $dirUrl !== '' ? $dirUrl . '/' . rawurlencode($baseName) : '';
        if ($fileUrl === '') {
            return;
        }
        $niceName = $niceNameMap[$filePath] ?? $this->prettifyFileName($baseName);
        if (isset($expanded[$filePath])) {
            if ($niceName !== '') {
                $expanded[$filePath]['display_name'] = $niceName;
            }
            return;
        }
        $expanded[$filePath] = ['file_path' => $filePath, 'file_url' => $fileUrl, 'display_name' => $niceName];
    }

    /**
     * @param array<string,string> $reference
     * @param array<string,array<string,string>> $expanded
     * @param array<string,array<string,string>> $directories
     */
    private function collectReferenceForExpansion(array $reference, array &$expanded, array &$directories): void {
        $filePath = isset($reference['file_path']) ? (string) $reference['file_path'] : '';
        $fileUrl = isset($reference['file_url']) ? (string) $reference['file_url'] : '';
        if ($filePath === '' || $fileUrl === '' || !is_readable($filePath)) {
            return;
        }
        $expanded[$filePath] = $reference;
        $directory = wp_normalize_path((string) dirname($filePath));
        if ($directory !== '') {
            $directories[$directory] = ['dir_url' => untrailingslashit((string) dirname($fileUrl))];
        }
    }

    /**
     * @param array<string,mixed> $entry
     * @param array<string,array<string,mixed>> $overview
     * @param array<string,array<string,mixed>> $uploadedByHash
     */
    private function mergeEntryIntoOverview(array $entry, array &$overview, array $uploadedByHash, callable $buildOverviewEntry): void {
        $filePath = isset($entry['file_path']) ? (string) $entry['file_path'] : '';
        $fileUrl = isset($entry['file_url']) ? (string) $entry['file_url'] : '';
        if ($filePath === '' || $fileUrl === '' || !is_readable($filePath)) {
            return;
        }
        $hash = hash_file('sha256', $filePath);
        if (!$hash) {
            return;
        }
        $displayName = isset($entry['display_name']) ? trim((string) $entry['display_name']) : basename($filePath);
        $listId = isset($entry['list_id']) ? max(1, (int) $entry['list_id']) : 1;
        if (isset($overview[$hash])) {
            $overview[$hash]['managed_by_simple_file_list'] = true;
            $overview[$hash]['simple_file_list_id'] = $listId;
            if ($displayName !== '' && (empty($overview[$hash]['nice_name']) || $overview[$hash]['nice_name'] === basename($filePath))) {
                $overview[$hash]['nice_name'] = $displayName;
            }
            return;
        }
        $overview[$hash] = $buildOverviewEntry($hash, $filePath, $fileUrl, $displayName, $uploadedByHash[$hash] ?? null, true);
        $overview[$hash]['simple_file_list_id'] = $listId;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,array<string,mixed>> $entries
     */
    private function processSimpleFileListEntryRow(array $row, array &$entries): void {
        if (!is_array($row) || !isset($row['option_value'])) {
            return;
        }
        $optionName = isset($row['option_name']) ? (string) $row['option_name'] : 'unknown-option';
        $value = maybe_unserialize($row['option_value']);
        if (!is_array($value)) {
            error_log('geweb-ai-search: File List option ' . $optionName . ' did not unserialize to an array.');
            return;
        }
        $listId = $this->extractListIdFromOptionName($optionName);
        $fileListDir = $this->resolveFileListDirForOption($optionName);
        foreach ($value as $record) {
            if (!is_array($record)) {
                continue;
            }
            $entry = $this->buildEntryFromRecord($record, $listId, $fileListDir);
            if ($entry !== null) {
                $entries[$entry['file_path']] = $entry;
            }
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,array<string,string>> $targets
     */
    private function processRemoveRow(array $row, string $normalizedPath, array $targets, bool &$removedAny): void {
        if (!is_array($row) || !isset($row['option_name'], $row['option_value'])) {
            return;
        }
        $optionName = (string) $row['option_name'];
        $value = maybe_unserialize($row['option_value']);
        if (!is_array($value)) {
            return;
        }
        $fileListDir = $this->resolveFileListDirForOption($optionName);
        $updatedValue = [];
        $removedFromOption = false;
        foreach ($value as $record) {
            if ($this->doesRemoveRecordMatch($record, $normalizedPath, $targets, $fileListDir)) {
                $removedFromOption = true;
                $removedAny = true;
                continue;
            }
            $updatedValue[] = $record;
        }
        if ($removedFromOption) {
            update_option($optionName, array_values($updatedValue), false);
        }
    }

    /**
     * @param mixed $record
     * @param array<string,array<string,string>> $targets
     */
    private function doesRemoveRecordMatch($record, string $normalizedPath, array $targets, string $fileListDir): bool {
        if (!is_array($record)) {
            return false;
        }
        $recordPath = isset($record['FilePath']) ? trim((string) $record['FilePath']) : '';
        if ($recordPath === '') {
            return false;
        }
        $resolved = $this->resolveSimpleFileListRecordPath($recordPath, $fileListDir);
        if (is_array($resolved) && ($resolved['file_path'] ?? '') === $normalizedPath) {
            return true;
        }

        return $this->matchSimpleFileListRecordPath($recordPath, $targets) === $normalizedPath;
    }

    /**
     * @param array<int,string> $filePaths
     * @return array<string,array<string,string>>
     */
    private function buildSimpleFileListTargets(array $filePaths): array {
        $targets = [];
        $uploads = wp_get_upload_dir();
        $baseDir = wp_normalize_path((string) ($uploads['basedir'] ?? ''));

        foreach ($filePaths as $filePath) {
            $normalizedPath = wp_normalize_path((string) $filePath);
            if ($normalizedPath === '') {
                continue;
            }
            $targets[$normalizedPath] = [
                'basename' => basename($normalizedPath),
                'relative_path' => $baseDir !== '' && strpos($normalizedPath, $baseDir) === 0
                    ? ltrim(substr($normalizedPath, strlen($baseDir)), '/')
                    : basename($normalizedPath),
            ];
        }

        return $targets;
    }

    /**
     * @param array<int,string> $directories
     * @return array<string,array<string,string>>
     */
    private function buildDirectoryFileTargets(array $directories): array {
        $filePaths = [];
        foreach ($directories as $directory) {
            foreach ($this->getSupportedFilesInDirectory($directory) as $filePath) {
                $filePaths[] = $filePath;
            }
        }

        return $this->buildSimpleFileListTargets($filePaths);
    }

    /**
     * @return array<int,string>
     */
    private function getSupportedFilesInDirectory(string $directory): array {
        $directory = wp_normalize_path($directory);
        if ($directory === '' || !is_dir($directory) || !is_readable($directory)) {
            return [];
        }

        $files = [];
        try {
            $iterator = new \DirectoryIterator($directory);
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                    continue;
                }

                $filePath = wp_normalize_path($fileInfo->getPathname());
                if ($filePath !== '' && is_readable($filePath) && $this->isSupportedSimpleFileListFile($filePath)) {
                    $files[] = $filePath;
                }
            }
        } catch (\Throwable $e) {
            error_log('geweb-ai-search: could not scan File List directory ' . $directory . ': ' . $e->getMessage());
            return [];
        }

        sort($files, SORT_NATURAL | SORT_FLAG_CASE);
        return $files;
    }

    private function isSupportedSimpleFileListFile(string $filePath): bool {
        $extension = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));
        if ($extension === '' || in_array($extension, ['php', 'phtml', 'phar', 'js', 'css', 'html', 'htm', 'svg'], true)) {
            return false;
        }

        $mimeType = strtolower(trim((new DocumentStore())->resolveMimeType($filePath)));
        if ($mimeType === '') {
            return false;
        }

        if (strpos($mimeType, 'text/') === 0 || strpos($mimeType, 'image/') === 0) {
            return true;
        }

        return in_array($mimeType, [
            'application/pdf',
            'application/msword',
            'application/vnd.ms-excel',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ], true);
    }

    /**
     * @return array<int,string>
     */
    private function getSflCandidateTableNames(): array {
        if ($this->isUsingAvpvhFileList()) {
            return [];
        }

        global $wpdb;
        return array_values(array_unique(array_filter(array_merge(
            $wpdb->get_col($wpdb->prepare(self::SQL_SHOW_TABLES_LIKE, $wpdb->esc_like($wpdb->prefix . 'eeSFL') . '%')),
            $wpdb->get_col($wpdb->prepare(self::SQL_SHOW_TABLES_LIKE, $wpdb->esc_like($wpdb->prefix . 'simple_file_list') . '%')),
            $wpdb->get_col($wpdb->prepare(self::SQL_SHOW_TABLES_LIKE, $wpdb->esc_like($wpdb->prefix . 'eesfl') . '%'))
        ))));
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,array<string,string>> $targets
     * @param array<string,string> $niceNames
     */
    private function processNiceNameFileListRow(array $row, array $targets, array &$niceNames): void {
        if (!is_array($row) || !isset($row['option_value'])) {
            return;
        }
        $optionName = isset($row['option_name']) ? (string) $row['option_name'] : 'unknown-option';
        $value = maybe_unserialize($row['option_value']);
        if (!is_array($value)) {
            error_log('geweb-ai-search: File List option ' . $optionName . ' did not unserialize to an array.');
            return;
        }
        foreach ($value as $record) {
            if (is_array($record)) {
                $this->applyNiceNameFromRecord($record, $targets, $niceNames, $optionName);
            }
        }
    }

    /**
     * @param array<string,mixed> $record
     * @param array<string,array<string,string>> $targets
     * @param array<string,string> $niceNames
     */
    private function applyNiceNameFromRecord(array $record, array $targets, array &$niceNames, string $optionName): void {
        $filePathValue = isset($record['FilePath']) ? trim((string) $record['FilePath']) : '';
        $niceName = isset($record['FileNiceName']) ? trim((string) $record['FileNiceName']) : '';
        if ($filePathValue === '' || $niceName === '') {
            return;
        }
        $matchedPath = $this->matchSimpleFileListRecordPath($filePathValue, $targets);
        if ($matchedPath === null || isset($niceNames[$matchedPath])) {
            return;
        }
        $niceNames[$matchedPath] = $niceName;
        error_log('geweb-ai-search: File List nice-name match from ' . $optionName . ' for ' . basename($matchedPath) . ' => ' . $niceName);
    }

    /**
     * @param array<string,array<string,string>> $targets
     */
    public function matchSimpleFileListRecordPath(string $filePathValue, array $targets): ?string {
        $normalizedValue = wp_normalize_path(ltrim(trim($filePathValue), '/'));

        foreach ($targets as $filePath => $target) {
            $basename = wp_normalize_path((string) ($target['basename'] ?? basename($filePath)));
            $relativePath = wp_normalize_path(ltrim((string) ($target['relative_path'] ?? ''), '/'));
            if (
                $normalizedValue === $basename
                || $normalizedValue === $relativePath
                || str_ends_with($relativePath, '/' . $normalizedValue)
            ) {
                return $filePath;
            }
        }

        return null;
    }

    /**
     * @param array<string,string> $stringFields
     */
    private function findModifiedTimestampFromFields(array $stringFields): ?int {
        foreach ($stringFields as $fieldKey => $fieldValue) {
            if (!preg_match('/modified|updated|changed|date|time|timestamp/i', $fieldKey)) {
                continue;
            }
            $parsed = $this->parseSimpleFileListTimestamp($fieldValue);
            if ($parsed > 0) {
                return $parsed;
            }
        }
        return null;
    }

    private function parseSimpleFileListTimestamp(string $value): int {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        if (ctype_digit($value)) {
            $timestamp = (int) $value;
            if ($timestamp > 1000000000) {
                return $timestamp;
            }
        }

        $parsed = strtotime($value);
        return $parsed !== false ? (int) $parsed : 0;
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,string>
     */
    private function buildStringFields(array $record): array {
        $stringFields = [];
        foreach ($record as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $stringValue = trim((string) $value);
            if ($stringValue !== '') {
                $stringFields[(string) $key] = $stringValue;
            }
        }
        return $stringFields;
    }

    /**
     * @param array<string,string> $stringFields
     */
    private function isTargetMatchedByFields(array $stringFields, string $basename, string $relativePath): bool {
        foreach ($stringFields as $fieldValue) {
            $normalizedField = wp_normalize_path($fieldValue);
            if (
                $fieldValue === $basename ||
                $normalizedField === wp_normalize_path($relativePath) ||
                str_ends_with($normalizedField, '/' . $basename) ||
                strpos($normalizedField, $basename) !== false
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,string> $stringFields
     */
    private function findNiceNameFromMatchedFields(array $stringFields, string $basename): ?string {
        foreach ($stringFields as $fieldKey => $fieldValue) {
            if (!preg_match('/nice|display|title|label/i', $fieldKey)) {
                continue;
            }
            $niceName = trim($fieldValue);
            if ($this->isUsableSimpleFileListNiceName($niceName, $basename)) {
                return $niceName;
            }
        }
        foreach ($stringFields as $fieldKey => $fieldValue) {
            if (!preg_match('/name/i', $fieldKey) || preg_match('/file|path|url|ext|type/i', $fieldKey)) {
                continue;
            }
            $niceName = trim($fieldValue);
            if ($this->isUsableSimpleFileListNiceName($niceName, $basename)) {
                return $niceName;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $reference
     */
    public function resolveOverviewDisplayName(array $reference, string $filePath): string {
        $displayName = isset($reference['display_name']) ? trim((string) $reference['display_name']) : '';
        if ($displayName !== '') {
            return $displayName;
        }

        return $this->prettifyFileName(basename($filePath));
    }

    public function prettifyFileName(string $fileName): string {
        $name = pathinfo($fileName, PATHINFO_FILENAME);
        if ($name === '') {
            return $fileName;
        }

        $name = str_replace(['_', '-'], ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        $name = trim((string) $name);

        return $name !== '' ? $name : $fileName;
    }

    public function isUsableSimpleFileListNiceName(string $niceName, string $basename): bool {
        if ($niceName === '' || $niceName === $basename) {
            return false;
        }

        return strpos($niceName, '/') === false && strpos($niceName, '\\') === false;
    }
}
