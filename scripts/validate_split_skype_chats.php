<?php
declare(strict_types=1);

require_once __DIR__ . '/split-skype-chats.php';

function geweb_chat_assert(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function geweb_chat_remove_test_tree(string $directory): void {
    if (!is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($directory);
}

$testRoot = sys_get_temp_dir() . '/geweb-chat-split-' . bin2hex(random_bytes(6));
$inputPath = $testRoot . '-input.txt';
$source = <<<'TXT'
Chat "Project"

2023/01/02 11:00:00 19:abc@thread.skype: <addmember><target>8:johndoe</target><target>8:live:john_99999</target><target>Grmt</target></addmember>
2023/01/02 11:03:00 John Doe: Second message
2023/01/02 11:02:00 Grmt: First message

Chat "Website"

2023/02/03 09:00:00 19:def@thread.skype: <addmember><target>8:simonk1</target><target>8:julesz</target></addmember>
2023/02/03 09:01:00 simonk1: <URIObject><OriginalName v="notes.pdf"></OriginalName></URIObject>

Chat "Noise only"

2023/03/04 10:00:00 19:ghi@thread.skype: <addmember><target>8:johndoe</target></addmember>
TXT;

geweb_chat_alias_maps([
    'aliases' => ['Grmt' => 'garmt', 'johndoe' => 'john', 'live:john_99999' => 'john', 'simonk1' => 'simon_k', 'julesz' => 'jules'],
    'display_names' => ['grmt' => 'garmt', 'john doe' => 'john'],
]);

try {
    geweb_chat_assert(file_put_contents($inputPath, $source) !== false, 'Could not create test input.');
    $manifest = geweb_chat_split_file($inputPath, $testRoot);
    geweb_chat_assert($manifest['conversation_count'] === 2, 'Expected two conversations.');
    geweb_chat_assert($manifest['source_conversation_count'] === 3, 'Expected three source conversations.');
    geweb_chat_assert($manifest['skipped_conversation_count'] === 1, 'Expected one noise-only conversation to be skipped.');
    geweb_chat_assert($manifest['document_count'] === 2, 'Expected two Markdown documents.');
    geweb_chat_assert(is_file($testRoot . '/REVIEW.md'), 'Review index was not generated.');
    $paths = array_column($manifest['files'], 'path');
    geweb_chat_assert(strpos($paths[0], 'garmt__john/project/') === 0, 'John aliases were not merged.');
    geweb_chat_assert(strpos($paths[1], 'garmt__jules__simon_k/website/') === 0, 'Simon/Jules mappings were not applied.');
    $first = (string) file_get_contents($testRoot . '/' . $paths[0]);
    geweb_chat_assert(strpos($first, 'First message') < strpos($first, 'Second message'), 'Messages were not ordered chronologically.');
    geweb_chat_assert(strpos($first, '<addmember>') === false, 'Membership XML was not removed.');
    $second = (string) file_get_contents($testRoot . '/' . $paths[1]);
    geweb_chat_assert(strpos($second, '[Attachment: notes.pdf]') !== false, 'Attachment metadata was not preserved.');
    $review = (string) file_get_contents($testRoot . '/REVIEW.md');
    geweb_chat_assert(strpos($review, 'Project') !== false, 'Review index omitted a searchable chat.');
    geweb_chat_assert(strpos($review, 'Noise only') === false, 'Review index included a noise-only chat.');
    $chunkRecords = [
        ['timestamp' => '2023/01/01 10:00:00', 'sender' => 'A', 'body' => str_repeat('x', 40), 'sequence' => 0],
        ['timestamp' => '2023/01/01 10:01:00', 'sender' => 'B', 'body' => str_repeat('y', 40), 'sequence' => 1],
    ];
    geweb_chat_assert(count(geweb_chat_chunk_records($chunkRecords, 100)) === 2, 'Oversized chats were not chunked.');
    geweb_chat_assert(geweb_chat_display_title('null') === 'Call logs', 'Null chat title was not mapped to Call logs.');
    geweb_chat_assert(
        geweb_chat_visibility('simonk1', ['garmt', 'simon_k']) === 'private',
        'Direct Simon Kuppens chat was not private.'
    );
    geweb_chat_assert(
        geweb_chat_visibility('Website AVPvH', ['garmt', 'jules', 'simon_k']) === 'internal',
        'Simon Kuppens group chat was incorrectly marked private.'
    );
    geweb_chat_assert(
        geweb_chat_visibility('Project', ['garmt', 'ariane', 'john']) === 'private',
        'A group chat with an explicitly private participant was not private.'
    );
    geweb_chat_assert(
        geweb_chat_visibility('Self notes', ['garmt']) === 'internal',
        'Garmt-only content was incorrectly marked private.'
    );
    echo "Skype chat splitter checks passed.\n";
} finally {
    @unlink($inputPath);
    geweb_chat_remove_test_tree($testRoot);
}
