<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Repairs text-boundary artifacts before Markdown is indexed or embedded.
 */
class IndexableContentNormalizer {
    private const INVISIBLE_WORD_BREAKS = [
        "\u{00AD}",
        "\u{200B}",
        "\u{2060}",
    ];

    public static function normalizeSourceHtml(string $html): string {
        $normalized = str_replace(self::INVISIBLE_WORD_BREAKS, '', $html);
        $normalized = preg_replace('/<wbr\b[^>]*>/i', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/<\/mark>\s*<mark\b[^>]*>/i', ' ', $normalized) ?? $normalized;

        return self::repairLineEndHyphenation($normalized);
    }

    public static function normalizeMarkdown(string $markdown): string {
        $normalized = str_replace(["\r\n", "\r"], "\n", $markdown);
        $normalized = str_replace(self::INVISIBLE_WORD_BREAKS, '', $normalized);

        return self::repairLineEndHyphenation($normalized);
    }

    private static function repairLineEndHyphenation(string $text): string {
        return preg_replace(
            '/([\p{L}\p{N}])-\h*\n\h*(?!\p{Ll}+-)(?=\p{Ll})/u',
            '$1',
            $text
        ) ?? $text;
    }
}
