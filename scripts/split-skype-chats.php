<?php
/**
 * Split a Skype text export into participant/title/timestamp Markdown files.
 *
 * Usage: php scripts/split-skype-chats.php <all_chats.txt> <output-directory>
 */

declare(strict_types=1);

const GEWEB_CHAT_HEADER_PATTERN = '/^Chat "(.*)"$/m';
const GEWEB_CHAT_TIMESTAMP_PATTERN = '\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2}';
const GEWEB_CHAT_MAX_DOCUMENT_BYTES = 200000;

/**
 * Alias maps are personal data, so they live outside the repository in
 * scripts/skype-aliases.local.json (git-ignored) or the file named by
 * GEWEB_CHAT_ALIAS_FILE: {"aliases": {...}, "display_names": {...}}.
 *
 * @return array{aliases: array<string,string>, display_names: array<string,string>}
 */
function geweb_chat_alias_maps(?array $override = null): array {
    static $maps = null;
    if ($override !== null) {
        $maps = [
            'aliases' => (array) ($override['aliases'] ?? []),
            'display_names' => (array) ($override['display_names'] ?? []),
        ];
    }
    if ($maps === null) {
        $maps = ['aliases' => [], 'display_names' => []];
        $file = getenv('GEWEB_CHAT_ALIAS_FILE') ?: __DIR__ . '/skype-aliases.local.json';
        if (is_file($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded)) {
                $maps = [
                    'aliases' => (array) ($decoded['aliases'] ?? []),
                    'display_names' => (array) ($decoded['display_names'] ?? []),
                ];
            }
        }
    }
    return $maps;
}

/**
 * @return array<string,string>
 */
function geweb_chat_alias_map(): array {
    return geweb_chat_alias_maps()['aliases'];
}

/**
 * @return array<string,string>
 */
function geweb_chat_display_name_map(): array {
    return geweb_chat_alias_maps()['display_names'];
}

function geweb_chat_canonical_alias(string $alias): string {
    return preg_replace('/^[48]:/', '', trim($alias)) ?? trim($alias);
}

function geweb_chat_is_technical_identity(string $alias): bool {
    return $alias === ''
        || strpos($alias, '@thread.skype') !== false
        || strpos($alias, '@cast.skype') !== false
        || preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i', $alias) === 1;
}

function geweb_chat_slug(string $value, string $fallback = 'untitled'): string {
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $normalized = strtolower(is_string($ascii) ? $ascii : $value);
    $normalized = preg_replace('/[^a-z0-9]+/', '-', $normalized) ?? '';
    $normalized = trim($normalized, '-');
    return $normalized !== '' ? $normalized : $fallback;
}

function geweb_chat_limit_segment(string $segment, int $limit = 120): string {
    if (strlen($segment) <= $limit) {
        return $segment;
    }
    return substr($segment, 0, $limit - 13) . '-' . substr(hash('sha256', $segment), 0, 12);
}

function geweb_chat_unknown_participant(string $alias): string {
    if (strpos($alias, 'guest:') === 0 || strpos($alias, 'live:.cid.') === 0) {
        return 'unknown-' . substr(hash('sha256', $alias), 0, 8);
    }
    if (preg_match('/^\+\d+$/', $alias) === 1) {
        return 'phone-' . substr($alias, -4);
    }
    return geweb_chat_slug($alias, 'unknown-' . substr(hash('sha256', $alias), 0, 8));
}

function geweb_chat_participant_for_alias(string $alias): ?string {
    $alias = geweb_chat_canonical_alias($alias);
    if (geweb_chat_is_technical_identity($alias)) {
        return null;
    }
    $map = geweb_chat_alias_map();
    return $map[$alias] ?? geweb_chat_unknown_participant($alias);
}

function geweb_chat_participant_for_sender(string $sender): ?string {
    $aliasParticipant = geweb_chat_participant_for_alias($sender);
    $canonical = geweb_chat_canonical_alias($sender);
    if (isset(geweb_chat_alias_map()[$canonical])) {
        return $aliasParticipant;
    }
    $key = strtolower(trim($sender));
    $displayMap = geweb_chat_display_name_map();
    if (isset($displayMap[$key])) {
        return $displayMap[$key];
    }
    if (geweb_chat_is_technical_identity($canonical)) {
        return null;
    }
    $firstName = preg_split('/[\s.]+/u', trim($sender))[0] ?? '';
    return geweb_chat_slug($firstName, 'unknown');
}

/**
 * @return array<int,array{timestamp:string,sender:string,body:string,sequence:int}>
 */
function geweb_chat_parse_records(string $block): array {
    $pattern = '/^(' . GEWEB_CHAT_TIMESTAMP_PATTERN . ') ((?:19:[^ ]+@(?:thread|cast)\.skype)|(?:[48]:[^ ]+)|(?:[^:]+)): ?(.*)$/';
    $records = [];
    $current = null;
    $sequence = 0;
    foreach (preg_split('/\R/', $block) ?: [] as $line) {
        if (preg_match($pattern, $line, $matches) === 1) {
            if (is_array($current)) {
                $records[] = $current;
            }
            $current = ['timestamp' => $matches[1], 'sender' => trim($matches[2]), 'body' => $matches[3], 'sequence' => $sequence++];
        } elseif (is_array($current)) {
            $current['body'] .= "\n" . $line;
        }
    }
    if (is_array($current)) {
        $records[] = $current;
    }
    return $records;
}

function geweb_chat_clean_attachment(string $xml): string {
    if (preg_match('/<OriginalName\s+v="([^"]+)"/i', $xml, $matches) === 1) {
        return '[Attachment: ' . html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') . ']';
    }
    return '[Attachment]';
}

function geweb_chat_clean_body(string $body): string {
    $body = preg_replace_callback('/<URIObject\b.*?<\/URIObject>/is', static function (array $matches): string {
        return geweb_chat_clean_attachment($matches[0]);
    }, $body) ?? $body;
    $body = preg_replace('/<quote\b[^>]*authorname="([^"]+)"[^>]*>/i', "> $1: ", $body) ?? $body;
    $body = preg_replace('/<\/?legacyquote[^>]*>|<<<\s*/i', '', $body) ?? $body;
    $body = preg_replace('/<br\s*\/?>/i', "\n", $body) ?? $body;
    $body = preg_replace('/<ss\b[^>]*>(.*?)<\/ss>/is', '$1', $body) ?? $body;
    $body = strip_tags($body);
    $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $body = str_replace(["\u{00A0}", "\r"], [' ', ''], $body);
    $body = preg_replace('/[ \t]+/', ' ', $body) ?? $body;
    $body = preg_replace('/\n{3,}/', "\n\n", $body) ?? $body;
    return trim($body);
}

function geweb_chat_is_noise(array $record): bool {
    $sender = (string) $record['sender'];
    $body = ltrim((string) $record['body']);
    if (strpos($sender, '@thread.skype') !== false || strpos($sender, '@cast.skype') !== false) {
        return true;
    }
    return strpos($body, '<partlist ') === 0 || strpos($body, 'Call Log for Call ') === 0;
}

/**
 * @param array<int,array{timestamp:string,sender:string,body:string,sequence:int}> $records
 * @return array<int,array{timestamp:string,sender:string,body:string,sequence:int}>
 */
function geweb_chat_clean_records(array $records): array {
    $cleaned = [];
    foreach ($records as $record) {
        if (geweb_chat_is_noise($record)) {
            continue;
        }
        $body = geweb_chat_clean_body($record['body']);
        if ($body === '') {
            continue;
        }
        $record['body'] = $body;
        $cleaned[] = $record;
    }
    usort($cleaned, static function (array $left, array $right): int {
        return [$left['timestamp'], $left['sequence']] <=> [$right['timestamp'], $right['sequence']];
    });
    return $cleaned;
}

/**
 * @return array<int,string>
 */
function geweb_chat_extract_aliases(string $block): array {
    preg_match_all('/(?:identity="|<(?:initiator|target)>)([^"<\r\n]+)(?:"|<\/(?:initiator|target)>)/i', $block, $matches);
    $aliases = [];
    foreach ($matches[1] ?? [] as $alias) {
        $canonical = geweb_chat_canonical_alias((string) $alias);
        if (!geweb_chat_is_technical_identity($canonical)) {
            $aliases[$canonical] = true;
        }
    }
    return array_keys($aliases);
}

/**
 * @param array<int,array{timestamp:string,sender:string,body:string,sequence:int}> $records
 * @param array<int,string> $aliases
 * @return array<int,string>
 */
function geweb_chat_collect_participants(array $records, array $aliases): array {
    $participants = ['garmt' => true];
    foreach ($aliases as $alias) {
        $participant = geweb_chat_participant_for_alias($alias);
        if ($participant !== null) {
            $participants[$participant] = true;
        }
    }
    foreach ($records as $record) {
        $participant = geweb_chat_participant_for_sender($record['sender']);
        if ($participant !== null) {
            $participants[$participant] = true;
        }
    }
    $result = array_keys($participants);
    sort($result, SORT_NATURAL | SORT_FLAG_CASE);
    return $result;
}

function geweb_chat_format_timestamp(string $timestamp): string {
    return str_replace(['/', ':', ' '], ['-', '-', '_'], $timestamp);
}

/**
 * @param array<int,array{timestamp:string,sender:string,body:string,sequence:int}> $records
 * @return array{start:string,end:string}
 */
function geweb_chat_time_range(array $records): array {
    $timestamps = array_column($records, 'timestamp');
    sort($timestamps, SORT_STRING);
    $fallback = 'unknown-time';
    return ['start' => $timestamps[0] ?? $fallback, 'end' => $timestamps[count($timestamps) - 1] ?? $fallback];
}

/**
 * @param array{timestamp:string,sender:string,body:string,sequence:int} $record
 */
function geweb_chat_record_size(array $record): int {
    return strlen($record['timestamp']) + strlen($record['sender']) + strlen($record['body']) + 32;
}

/**
 * @param array<int,array{timestamp:string,sender:string,body:string,sequence:int}> $records
 * @return array<int,array<int,array{timestamp:string,sender:string,body:string,sequence:int}>>
 */
function geweb_chat_chunk_records(array $records, int $maxBytes = GEWEB_CHAT_MAX_DOCUMENT_BYTES): array {
    $chunks = [];
    $current = [];
    $currentBytes = 0;
    foreach ($records as $record) {
        $recordBytes = geweb_chat_record_size($record);
        if ($current !== [] && $currentBytes + $recordBytes > $maxBytes) {
            $chunks[] = $current;
            $current = [];
            $currentBytes = 0;
        }
        $current[] = $record;
        $currentBytes += $recordBytes;
    }
    if ($current !== []) {
        $chunks[] = $current;
    }
    return $chunks;
}

function geweb_chat_yaml_value(string $value): string {
    return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * @param array<int,string> $values
 */
function geweb_chat_yaml_list(array $values): string {
    return (string) json_encode(array_values($values), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * @param array<int,array{timestamp:string,sender:string,body:string,sequence:int}> $records
 * @param array<int,string> $participants
 * @param array<int,string> $aliases
 */
function geweb_chat_render_markdown(
    string $title,
    array $records,
    array $participants,
    array $aliases,
    int $blockNumber,
    int $partNumber,
    int $partCount,
    string $visibility
): string {
    $range = geweb_chat_time_range($records);
    $displayTitle = geweb_chat_display_title($title);
    $lines = [
        '---',
        'source: "Skype export"',
        'source_block: ' . $blockNumber,
        'chat_part: ' . $partNumber,
        'chat_part_count: ' . $partCount,
        'visibility: ' . geweb_chat_yaml_value($visibility),
        'chat_title: ' . geweb_chat_yaml_value($displayTitle),
        'started_at: ' . geweb_chat_yaml_value($range['start']),
        'ended_at: ' . geweb_chat_yaml_value($range['end']),
        'participants: ' . geweb_chat_yaml_list($participants),
        'aliases: ' . geweb_chat_yaml_list($aliases),
        '---',
        '',
        '# ' . $displayTitle,
        '',
        '**Participants:** ' . implode(', ', $participants),
        '',
    ];
    foreach ($records as $record) {
        $lines[] = '## ' . str_replace('/', '-', $record['timestamp']) . ' — ' . $record['sender'];
        $lines[] = '';
        $lines[] = $record['body'];
        $lines[] = '';
    }
    return rtrim(implode("\n", $lines)) . "\n";
}

function geweb_chat_part_suffix(int $partNumber, int $partCount): string {
    if ($partCount <= 1) {
        return '';
    }
    return sprintf('--part-%03d-of-%03d', $partNumber, $partCount);
}

function geweb_chat_review_link(string $relativePath): string {
    return implode('/', array_map('rawurlencode', explode('/', $relativePath)));
}

function geweb_chat_review_label(string $value): string {
    return str_replace(['\\', '[', ']'], ['\\\\', '\\[', '\\]'], $value);
}

function geweb_chat_display_title(string $title): string {
    return trim($title) === '' || strtolower(trim($title)) === 'null' ? 'Call logs' : trim($title);
}

/**
 * @return array<int,string>
 */
function geweb_chat_private_participants(): array {
    return [
        'aherlah',
        'ariane',
        'donatella',
        'evan',
        'sloboda',
        'unknown-1fa369c2',
        'unknown-6fd84b5c',
        'unknown-84054ae1',
        'unknown-ab68c419',
        'unknown-ddbbe994',
        'vica',
        'zita',
    ];
}

/**
 * Private conversations must be kept out of broadly searchable indexes.
 * Add explicit aliases or exported titles here as review decisions are made.
 *
 * @param array<int,string> $participants
 */
function geweb_chat_visibility(string $title, array $participants): string {
    $normalizedTitle = strtolower(trim($title));
    $privateTitles = ['skuppens1', 'simon kuppens kuppens'];
    $isExplicitPrivateTitle = in_array($normalizedTitle, $privateTitles, true);
    $isDirectSimonChat = count($participants) <= 2 && in_array('simon_k', $participants, true);
    $hasPrivateParticipant = array_intersect($participants, geweb_chat_private_participants()) !== [];
    return $isExplicitPrivateTitle || $isDirectSimonChat || $hasPrivateParticipant ? 'private' : 'internal';
}

/**
 * @param array<string,mixed> $manifest
 */
function geweb_chat_render_review_index(array $manifest): string {
    $lines = [
        '# Split chat review',
        '',
        '- Source conversations: ' . (int) ($manifest['source_conversation_count'] ?? 0),
        '- Searchable conversations: ' . (int) ($manifest['conversation_count'] ?? 0),
        '- Noise-only conversations omitted: ' . (int) ($manifest['skipped_conversation_count'] ?? 0),
        '- Review documents: ' . (int) ($manifest['document_count'] ?? 0),
        '',
        'These files are staged for review only. They have not been uploaded to Gemini or Meilisearch.',
        '',
        '## Documents',
        '',
    ];
    foreach ((array) ($manifest['files'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $path = (string) ($item['path'] ?? '');
        $title = geweb_chat_review_label(geweb_chat_display_title((string) ($item['title'] ?? '')));
        $participants = implode(', ', (array) ($item['participants'] ?? []));
        $partCount = (int) ($item['part_count'] ?? 1);
        $partLabel = $partCount > 1
            ? sprintf('; part %d/%d', (int) ($item['part'] ?? 1), $partCount)
            : '';
        $visibilityLabel = ($item['visibility'] ?? 'internal') === 'private' ? '; **PRIVÉ**' : '; intern';
        $lines[] = sprintf(
            '- [%s](%s) — %s; %d messages%s%s',
            $title,
            geweb_chat_review_link($path),
            geweb_chat_review_label($participants),
            (int) ($item['indexed_messages'] ?? 0),
            $partLabel,
            $visibilityLabel
        );
    }
    return implode("\n", $lines) . "\n";
}

/**
 * @param array<string,bool> $usedPaths
 */
function geweb_chat_resolve_output_path(string $root, string $participantFolder, string $titleFolder, string $baseName, array &$usedPaths): string {
    $relative = $participantFolder . '/' . $titleFolder . '/' . $baseName . '.md';
    $candidate = $relative;
    $suffix = 2;
    while (isset($usedPaths[$candidate])) {
        $candidate = $participantFolder . '/' . $titleFolder . '/' . $baseName . '--' . $suffix++ . '.md';
    }
    $usedPaths[$candidate] = true;
    return rtrim($root, '/') . '/' . $candidate;
}

function geweb_chat_ensure_directory(string $directory): void {
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create output directory: ' . $directory);
    }
}

/**
 * @return array<string,mixed>
 */
function geweb_chat_split_file(string $inputPath, string $outputRoot): array {
    $source = file_get_contents($inputPath);
    if (!is_string($source)) {
        throw new RuntimeException('Could not read input file: ' . $inputPath);
    }
    if (is_dir($outputRoot) && (new FilesystemIterator($outputRoot))->valid()) {
        throw new RuntimeException('Output directory must not already contain files: ' . $outputRoot);
    }
    geweb_chat_ensure_directory($outputRoot);
    $blocks = preg_split('/(?=^Chat ".*"$)/m', $source, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $usedPaths = [];
    $manifestItems = [];
    $blockNumber = 0;
    $indexedConversationCount = 0;
    $skippedConversationCount = 0;
    foreach ($blocks as $block) {
        if (preg_match(GEWEB_CHAT_HEADER_PATTERN, $block, $header) !== 1) {
            continue;
        }
        ++$blockNumber;
        $title = (string) $header[1];
        $allRecords = geweb_chat_parse_records($block);
        $aliases = geweb_chat_extract_aliases($block);
        $participants = geweb_chat_collect_participants($allRecords, $aliases);
        $records = geweb_chat_clean_records($allRecords);
        if ($records === []) {
            ++$skippedConversationCount;
            continue;
        }
        ++$indexedConversationCount;
        $displayTitle = geweb_chat_display_title($title);
        $visibility = geweb_chat_visibility($title, $participants);
        $participantFolder = geweb_chat_limit_segment(implode('__', $participants), 180);
        $titleFolder = geweb_chat_limit_segment(geweb_chat_slug($displayTitle));
        $chunks = geweb_chat_chunk_records($records);
        $partCount = count($chunks);
        foreach ($chunks as $partIndex => $chunk) {
            $partNumber = $partIndex + 1;
            $range = geweb_chat_time_range($chunk);
            $baseName = geweb_chat_format_timestamp($range['start']) . '--' . geweb_chat_format_timestamp($range['end']);
            $baseName .= geweb_chat_part_suffix($partNumber, $partCount);
            $path = geweb_chat_resolve_output_path($outputRoot, $participantFolder, $titleFolder, $baseName, $usedPaths);
            geweb_chat_ensure_directory(dirname($path));
            $markdown = geweb_chat_render_markdown(
                $title,
                $chunk,
                $participants,
                $aliases,
                $blockNumber,
                $partNumber,
                $partCount,
                $visibility
            );
            if (file_put_contents($path, $markdown) === false) {
                throw new RuntimeException('Could not write Markdown file: ' . $path);
            }
            $manifestItems[] = [
                'block' => $blockNumber,
                'part' => $partNumber,
                'part_count' => $partCount,
                'path' => ltrim(substr($path, strlen(rtrim($outputRoot, '/'))), '/'),
                'title' => $title,
                'display_title' => $displayTitle,
                'visibility' => $visibility,
                'participants' => $participants,
                'aliases' => $aliases,
                'source_records' => count($allRecords),
                'indexed_messages' => count($chunk),
                'sha256' => hash('sha256', $markdown),
            ];
        }
    }
    $manifest = [
        'source_file' => basename($inputPath),
        'source_sha256' => hash('sha256', $source),
        'source_conversation_count' => $blockNumber,
        'conversation_count' => $indexedConversationCount,
        'skipped_conversation_count' => $skippedConversationCount,
        'document_count' => count($manifestItems),
        'files' => $manifestItems,
    ];
    $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($manifestJson) || file_put_contents(rtrim($outputRoot, '/') . '/manifest.json', $manifestJson . "\n") === false) {
        throw new RuntimeException('Could not write manifest.');
    }
    $reviewIndex = geweb_chat_render_review_index($manifest);
    if (file_put_contents(rtrim($outputRoot, '/') . '/REVIEW.md', $reviewIndex) === false) {
        throw new RuntimeException('Could not write review index.');
    }
    return $manifest;
}

function geweb_chat_main(array $arguments): int {
    if (count($arguments) !== 3) {
        fwrite(STDERR, "Usage: php scripts/split-skype-chats.php <all_chats.txt> <output-directory>\n");
        return 2;
    }
    try {
        $manifest = geweb_chat_split_file($arguments[1], $arguments[2]);
        echo 'Generated ' . $manifest['document_count'] . ' Markdown documents from '
            . $manifest['conversation_count'] . " searchable chats.\n";
        return 0;
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . "\n");
        return 1;
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(geweb_chat_main($argv));
}
