<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Pure Gemini model-name and provider-error classification rules.
 */
class GeminiModelRules {
    /** Google's recommendation for new projects (models page, 2026-11). */
    public const RECOMMENDED_MODEL = 'gemini-3.8-flash';

    /**
     * @param array<int,string> $officialAliases
     * @param callable $isPermanentlyUnavailable Receives normalized model name.
     */
    public static function supportsFileSearch(string $model, array $officialAliases, callable $isPermanentlyUnavailable): bool {
        $normalizedModel = strtolower(trim($model));
        if ($normalizedModel === '') {
            return false;
        }

        if ($isPermanentlyUnavailable($normalizedModel)) {
            return false;
        }

        if (in_array($normalizedModel, $officialAliases, true)) {
            return true;
        }

        $blockedFragments = [
            'tts',
            'speech',
            'audio',
            'embedding',
            'image-generation',
            'vision-preview-generation',
            'image',
            'video',
            'live',
            'robotics',
            'deep-research',
            'computer-use',
        ];

        foreach ($blockedFragments as $fragment) {
            if (strpos($normalizedModel, $fragment) !== false) {
                return false;
            }
        }

        return self::isChatModelName($normalizedModel);
    }

    /**
     * Gemini 2/3 text models end in -pro, -flash or -flash-lite. Gemini 4 and later use
     * names such as gemini-4-argon, so any gemini-4+ base name counts too.
     */
    public static function isChatModelName(string $normalizedModel): bool {
        return preg_match('/^gemini-\d[a-z0-9.\-]*-(pro|flash|flash-lite)(?:-|$)/', $normalizedModel) === 1
            || preg_match('/^gemini-[4-9][0-9.]*-[a-z]+(?:-preview)?$/', $normalizedModel) === 1;
    }

    /**
     * Pro and Gemini 4+ reasoning models get the longer "Pro" timeout.
     */
    public static function usesProTimeout(string $model): bool {
        $normalizedModel = strtolower(trim($model));
        return str_contains($normalizedModel, 'pro') || preg_match('/^gemini-[4-9]/', $normalizedModel) === 1;
    }

    /**
     * Google's moving "-latest" aliases. They still work when saved, but are not offered
     * in model lists because their target changes without notice.
     */
    public static function isLatestAlias(string $model): bool {
        return preg_match('/^gemini-[a-z\-]+-latest$/', strtolower(trim($model))) === 1;
    }

    public static function isDeprecatedModel(string $model): bool {
        $normalized = strtolower(trim($model));
        if ($normalized === '') {
            return false;
        }

        if (preg_match('/^gemini-1\.[05]/', $normalized) === 1) {
            return true;
        }

        if (preg_match('/-001$|-002$/', $normalized) === 1) {
            return true;
        }

        return in_array($normalized, ['gemini-pro', 'gemini-flash', 'gemini-pro-vision'], true);
    }

    public static function extractRequestedModelFromUrl(string $url): string {
        if (preg_match('#/models/([^:/?]+):generateContent#', $url, $matches) !== 1) {
            return '';
        }

        return strtolower(trim((string) ($matches[1] ?? '')));
    }

    public static function extractHttpCodeFromMessage(string $message): int {
        if (preg_match('/HTTP code\s+(\d{3})/i', $message, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }

    public static function shouldMarkModelPermanentlyUnavailable(string $model, int $httpCode, string $message): bool {
        $normalizedModel = strtolower(trim($model));
        if ($normalizedModel === '' || $httpCode !== 404) {
            return false;
        }

        $normalizedMessage = strtolower($message);
        if ($normalizedMessage === '') {
            return false;
        }

        return strpos($normalizedMessage, $normalizedModel) !== false
            && (
                strpos($normalizedMessage, 'no longer available') !== false
                || strpos($normalizedMessage, 'not available') !== false
                || strpos($normalizedMessage, 'not found') !== false
            );
    }

    /**
     * @param array<int,string> $models
     * @param array<int,string> $officialAliases
     * @return array<int,string>
     */
    public static function prependOfficialLatestAliases(array $models, array $officialAliases): array {
        return array_values(array_unique(array_merge($officialAliases, $models)));
    }

    /**
     * @param array<int,string> $models
     * @return array<int,string>
     */
    public static function withoutLatestAliases(array $models, string $keep = ''): array {
        return array_values(array_filter($models, static function ($model) use ($keep): bool {
            return $model === $keep || !self::isLatestAlias((string) $model);
        }));
    }

    public static function isGemini2Model(string $model): bool {
        return strpos($model, 'gemini-2') === 0;
    }

    public static function supportsThoughtSummaries(string $model): bool {
        $normalizedModel = strtolower(trim($model));
        return strpos($normalizedModel, 'gemini-3') === 0
            || strpos($normalizedModel, 'gemini-2.5') === 0;
    }

    /**
     * @param array<int,string> $fragments
     */
    public static function messageContainsAny(string $message, array $fragments): bool {
        foreach ($fragments as $fragment) {
            if ($fragment !== '' && stripos($message, $fragment) !== false) {
                return true;
            }
        }

        return false;
    }
}
