<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Static facts about Gemini text models, for the admin model table.
 *
 * Dates and prices come from Google's models, pricing, changelog and deprecations
 * pages (checked 2026-11-18). Speed, quality and value are a rough summary of
 * Google's own positioning and the price, on a scale from -- to +++; they are not
 * benchmarks. Update this list when Google releases or retires models.
 */
class GeminiModelCatalog {
    public const CHECKED_ON = '2026-11-18';

    /**
     * released, deprecated (announced), shutdown (earliest), replacement,
     * price per 1M tokens in/out (USD, paid tier), price_note, free tier,
     * speed, quality, value.
     *
     * @var array<string,array<string,mixed>>
     */
    private const MODELS = [
        'gemini-4-carbon' => [
            'released' => '2026-11-10', 'price' => '$2 / $10', 'price_note' => '$4 / $20 from 2026-12-01', 'free' => false,
            'speed' => '-', 'quality' => '+++', 'value' => '--',
            'summary' => 'Most capable reasoning model; slow and expensive.',
        ],
        'gemini-4-argon' => [
            'released' => '2026-10-13', 'price' => '$2 / $10', 'price_note' => '$4 / $20 from 2026-12-01', 'free' => false,
            'speed' => '+', 'quality' => '++', 'value' => '-',
            'summary' => 'Faster and uses fewer tokens per turn than Carbon.',
        ],
        'gemini-4-hydrogen' => [
            'released' => '2026-11-12', 'price' => '$1.50 / $7.50', 'free' => true,
            'speed' => '++', 'quality' => '++', 'value' => '+',
            'summary' => 'Gemini 4 for everyday tasks, built for speed and efficiency.',
        ],
        'gemini-3.8-flash' => [
            'released' => '2026-09-02', 'price' => '$0.75 / $3.75', 'price_note' => '$1.50 / $7.50 from 2027-01-01', 'free' => true,
            'speed' => '++', 'quality' => '++', 'value' => '+++',
            'summary' => 'Most intelligent Flash model; Google recommends it for new projects.',
        ],
        'gemini-3.7-flash' => [
            'released' => '2026-08-13', 'deprecated' => '2026-10-08', 'replacement' => 'gemini-3.8-flash',
            'speed' => '++', 'quality' => '++', 'value' => '+',
            'summary' => 'Deprecated; requests go to gemini-3.8-flash.',
        ],
        'gemini-3.6-flash' => [
            'released' => '2026-07-21', 'price' => '$0.75 / $3.75', 'price_note' => '$1.50 / $7.50 from 2027-01-01', 'free' => true,
            'speed' => '++', 'quality' => '+', 'value' => '+',
            'summary' => 'Previous Flash generation; same price as 3.8, so 3.8 is the better choice.',
        ],
        'gemini-3.5-flash' => [
            'released' => '2026-05-19', 'deprecated' => '2026-10-08', 'replacement' => 'gemini-3.6-flash',
            'speed' => '++', 'quality' => '+', 'value' => '+',
            'summary' => 'Deprecated; requests go to gemini-3.6-flash.',
        ],
        'gemini-3.5-flash-lite' => [
            'released' => '2026-07-21', 'price' => '$0.30 / $2.50', 'free' => true,
            'speed' => '+++', 'quality' => '-', 'value' => '++',
            'summary' => 'Fastest and cheapest current model; for simple, high-volume work.',
        ],
        'gemini-3.1-pro-preview' => [
            'released' => '2026-02-19', 'preview' => true, 'price' => '$2 / $12', 'free' => false,
            'speed' => '-', 'quality' => '++', 'value' => '--',
            'summary' => 'Older Pro preview; Gemini 4 is newer and cheaper per answer.',
        ],
        'gemini-3.1-flash-lite' => [
            'released' => '2026-05-07', 'shutdown' => '2027-05-07', 'replacement' => 'gemini-3.5-flash-lite', 'price' => '$0.25 / $1.50', 'free' => true,
            'speed' => '+++', 'quality' => '-', 'value' => '+',
            'summary' => 'Cheap and fast; to be replaced by gemini-3.5-flash-lite.',
        ],
        'gemini-3.1-flash-lite-preview' => [
            'released' => '2026-03-03', 'preview' => true, 'shutdown' => '2026-05-25', 'replacement' => 'gemini-3.1-flash-lite',
            'speed' => '+++', 'quality' => '-', 'value' => '-',
            'summary' => 'Shut down; use gemini-3.1-flash-lite or 3.5-flash-lite.',
        ],
        'gemini-3-flash-preview' => [
            'released' => '2025-12-17', 'preview' => true, 'replacement' => 'gemini-3.6-flash', 'price' => '$0.50 / $3', 'free' => true,
            'speed' => '++', 'quality' => '-', 'value' => '-',
            'summary' => 'Legacy Flash preview.',
        ],
        'gemini-3-pro-preview' => [
            'released' => '2025-11-18', 'preview' => true, 'shutdown' => '2026-03-09', 'replacement' => 'gemini-3.1-pro-preview',
            'speed' => '-', 'quality' => '+', 'value' => '--',
            'summary' => 'Shut down.',
        ],
        'gemini-2.5-pro' => [
            'released' => '2025-06-17', 'deprecated' => '2026-11-02', 'shutdown' => '2026-12-01', 'replacement' => 'gemini-3.8-flash', 'price' => '$1.25 / $10', 'free' => true,
            'speed' => '-', 'quality' => '+', 'value' => '-',
            'summary' => 'Deprecated; shuts down 2026-12-01.',
        ],
        'gemini-2.5-flash' => [
            'released' => '2025-06-17', 'deprecated' => '2026-11-02', 'shutdown' => '2026-12-01', 'replacement' => 'gemini-3.5-flash-lite', 'price' => '$0.30 / $2.50', 'free' => true,
            'speed' => '++', 'quality' => '-', 'value' => '-',
            'summary' => 'Deprecated; shuts down 2026-12-01.',
        ],
        'gemini-2.5-flash-lite' => [
            'released' => '2025-07-22', 'price' => '$0.10 / $0.40', 'free' => true,
            'speed' => '+++', 'quality' => '--', 'value' => '+',
            'summary' => 'Very cheap but the weakest model here.',
        ],
    ];

    /**
     * @return array<string,mixed>|null
     */
    public static function get(string $model): ?array {
        $entry = self::MODELS[strtolower(trim($model))] ?? null;
        return is_array($entry) ? $entry : null;
    }

    /**
     * Deprecated, or past its announced shutdown date.
     */
    public static function isRetired(string $model, ?string $today = null): bool {
        $entry = self::get($model);
        if ($entry === null) {
            return false;
        }

        $today = $today ?? gmdate('Y-m-d');
        $deprecated = (string) ($entry['deprecated'] ?? '');
        $shutdown = (string) ($entry['shutdown'] ?? '');

        return ($deprecated !== '' && $deprecated <= $today) || ($shutdown !== '' && $shutdown <= $today);
    }
}
