<?php
declare(strict_types=1);

use Geweb\AISearch\IndexableContentNormalizer;
use PHPUnit\Framework\TestCase;

final class IndexableContentNormalizerTest extends TestCase {
    public function testNormalizeSourceHtmlPreservesAdjacentWordBoundary(): void {
        $normalized = IndexableContentNormalizer::normalizeSourceHtml('<mark>aandeelhouders noch</mark><mark>derden</mark>');
        $stripped = preg_replace('/<\/?mark\b[^>]*>/i', '', $normalized) ?? $normalized;
        self::assertSame('aandeelhouders noch derden', $stripped);
    }

    public function testNormalizeMarkdownRepairsHyphenationAndSoftHyphens(): void {
        self::assertSame('developer', IndexableContentNormalizer::normalizeMarkdown("devel-\noper"));
        self::assertSame('developer', IndexableContentNormalizer::normalizeMarkdown("devel-  \noper"));
        self::assertSame('developer', IndexableContentNormalizer::normalizeMarkdown("dev\u{00AD}eloper"));
        self::assertSame("aandeelhouders noch\nderden", IndexableContentNormalizer::normalizeMarkdown("aandeelhouders noch\nderden"));
        self::assertSame("state-\nof-the-art", IndexableContentNormalizer::normalizeMarkdown("state-\nof-the-art"));
    }
}
