<?php
declare(strict_types=1);

use Geweb\AISearch\GeminiModelRules;
use PHPUnit\Framework\TestCase;

final class GeminiModelRulesTest extends TestCase {
    public function testModelLabelsAndAliasFiltering(): void {
        $labels = GeminiModelRules::buildModelOptionLabels(
            ['gemini-3.8-flash', 'gemini-3.5-flash-lite', 'gemini-4-argon'],
            'gemini-3.8-flash'
        );

        self::assertSame('gemini-3.8-flash (default, recommended)', $labels['gemini-3.8-flash']);
        self::assertSame('gemini-3.5-flash-lite (fast, low cost)', $labels['gemini-3.5-flash-lite']);
        self::assertArrayNotHasKey('gemini-4-argon', $labels);

        self::assertTrue(GeminiModelRules::isLatestAlias('gemini-flash-latest'));
        self::assertFalse(GeminiModelRules::isLatestAlias('gemini-3.8-flash'));

        $filtered = GeminiModelRules::withoutLatestAliases(
            ['gemini-flash-latest', 'gemini-pro-latest', 'gemini-3.8-flash'],
            'gemini-flash-latest'
        );
        self::assertSame(['gemini-flash-latest', 'gemini-3.8-flash'], $filtered);
    }

    public function testChatModelAndTimeoutClassification(): void {
        self::assertTrue(GeminiModelRules::isChatModelName('gemini-3.8-flash'));
        self::assertTrue(GeminiModelRules::isChatModelName('gemini-4-argon'));
        self::assertTrue(GeminiModelRules::usesProTimeout('gemini-4-carbon'));
        self::assertTrue(GeminiModelRules::usesProTimeout('gemini-2.5-pro'));
        self::assertFalse(GeminiModelRules::usesProTimeout('gemini-3.8-flash'));

        self::assertFalse(GeminiModelRules::supportsFileSearch('gemini-2.5-flash-tts', [], static fn() => false));
        self::assertTrue(GeminiModelRules::supportsFileSearch('gemini-4-hydrogen', [], static fn() => false));
    }

    public function testErrorAndUrlHelpers(): void {
        self::assertSame(
            'gemini-3.8-flash',
            GeminiModelRules::extractRequestedModelFromUrl('https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent')
        );
        self::assertSame(404, GeminiModelRules::extractHttpCodeFromMessage('Request failed with HTTP code 404'));
        self::assertTrue(GeminiModelRules::shouldMarkModelPermanentlyUnavailable('gemini-1.5-pro', 404, 'Model gemini-1.5-pro is no longer available'));
        self::assertFalse(GeminiModelRules::shouldMarkModelPermanentlyUnavailable('gemini-3.8-flash', 503, 'Service unavailable'));
    }
}
