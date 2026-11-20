<?php
declare(strict_types=1);

define('ABSPATH', dirname(__DIR__) . '/');
require_once ABSPATH . 'classes/Indexing/IndexableContentNormalizer.php';

use Geweb\AISearch\IndexableContentNormalizer;

$cases = [
    'adjacent highlights preserve a word boundary' => [
        '<mark>aandeelhouders noch</mark><mark>derden</mark>',
        'aandeelhouders noch derden',
        'html',
    ],
    'line-end hyphenation is repaired' => [
        "devel-\noper",
        'developer',
        'markdown',
    ],
    'Markdown hard-break hyphenation is repaired' => [
        "devel-  \noper",
        'developer',
        'markdown',
    ],
    'soft hyphens are removed' => [
        "dev\u{00AD}eloper",
        'developer',
        'markdown',
    ],
    'separate words remain separate' => [
        "aandeelhouders noch\nderden",
        "aandeelhouders noch\nderden",
        'markdown',
    ],
    'explicit compounds retain their hyphen' => [
        "state-\nof-the-art",
        "state-\nof-the-art",
        'markdown',
    ],
];

$failures = [];
foreach ($cases as $label => [$input, $expected, $type]) {
    $actual = $type === 'html'
        ? IndexableContentNormalizer::normalizeSourceHtml($input)
        : IndexableContentNormalizer::normalizeMarkdown($input);
    if ($type === 'html') {
        $actual = preg_replace('/<\/?mark\b[^>]*>/i', '', $actual) ?? $actual;
    }
    if ($actual !== $expected) {
        $failures[] = sprintf('%s: expected %s, got %s', $label, var_export($expected, true), var_export($actual, true));
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Indexable content normalization checks passed.\n";
