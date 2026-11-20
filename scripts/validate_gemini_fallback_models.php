<?php

declare(strict_types=1);

$repoRoot = dirname(__DIR__);
$providerConfigPath = $repoRoot . '/classes/Providers/GeminiHelpersTrait.php';
$modelRegistryPath = $repoRoot . '/classes/Providers/GeminiModelRegistry.php';

if (!is_file($providerConfigPath) || !is_file($modelRegistryPath)) {
    fwrite(STDERR, "Required source files are missing.\n");
    exit(1);
}

$apiKey = trim((string) getenv('GEMINI_API_KEY'));
if ($apiKey === '') {
    fwrite(STDERR, "GEMINI_API_KEY is required.\n");
    exit(1);
}

$providerConfigSource = (string) file_get_contents($providerConfigPath);
$modelRegistrySource = (string) file_get_contents($modelRegistryPath);

$defaultModel = extractDefaultModel($providerConfigSource);
$fallbackModels = extractQuotedArrayFromMethod($modelRegistrySource, 'getDefaultModels');
if ($defaultModel !== '' && !in_array($defaultModel, $fallbackModels, true)) {
    array_unshift($fallbackModels, $defaultModel);
}

$errors = [];
$warnings = [];

if ($defaultModel === '') {
    $errors[] = 'Could not extract DEFAULT_MODEL from Gemini provider.';
}

if ($fallbackModels === []) {
    $errors[] = 'Could not extract bundled fallback models from Gemini provider.';
}

if ($errors !== []) {
    renderMessages($errors, $warnings);
    exit(1);
}

if (!in_array($defaultModel, $fallbackModels, true)) {
    $errors[] = sprintf('Default model "%s" is not present in the fallback model list.', $defaultModel);
}

$liveModels = fetchLiveGeminiModels($apiKey);
if ($liveModels === []) {
    $errors[] = 'Gemini ListModels returned no generateContent-capable models.';
    renderMessages($errors, $warnings);
    exit(1);
}

foreach ($fallbackModels as $model) {
    if (!in_array($model, $liveModels, true)) {
        $errors[] = sprintf('Fallback model "%s" is not present in the live Gemini model list.', $model);
    }
}

$candidateModels = array_values(array_filter($liveModels, static function (string $model): bool {
    $blockedFragments = [
        'tts',
        'speech',
        'audio',
        'embedding',
        'image-generation',
        'vision-preview-generation',
        'live',
        'native-audio',
        'image',
        'video',
        'robotics',
        'deep-research',
        'computer-use',
    ];

    foreach ($blockedFragments as $fragment) {
        if (strpos($model, $fragment) !== false) {
            return false;
        }
    }

    return preg_match('/^gemini-\d[a-z0-9.\-]*-(pro|flash|flash-lite)(?:-|$)/', $model) === 1;
}));

$missingCandidates = array_values(array_diff($candidateModels, $fallbackModels));
if ($missingCandidates !== []) {
    $errors[] = 'Live Gemini model candidates missing from the fallback list: ' . implode(', ', $missingCandidates);
}

renderMessages($errors, $warnings);

if ($errors !== []) {
    exit(1);
}

fwrite(STDOUT, "Gemini fallback model validation passed.\n");

function extractDefaultModel(string $source): string
{
    if (!preg_match("/private const DEFAULT_MODEL = '([^']+)'/", $source, $matches)) {
        return '';
    }

    return trim((string) ($matches[1] ?? ''));
}

/**
 * @return array<int,string>
 */
function extractQuotedArrayFromMethod(string $source, string $methodName): array
{
    $pattern = sprintf('/function %s\([^{]*\)\s*:[^{]+\{(.*?)\n    \}/s', preg_quote($methodName, '/'));
    if (!preg_match($pattern, $source, $matches)) {
        return [];
    }

    if (!preg_match_all("/'([^']+)'/", (string) ($matches[1] ?? ''), $valueMatches)) {
        return [];
    }

    return array_values(array_unique(array_map('strval', $valueMatches[1])));
}

/**
 * @return array<int,string>
 */
function fetchLiveGeminiModels(string $apiKey): array
{
    $url = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode($apiKey);
    [$response, $statusLine] = fetchGeminiListModelsResponse($url);
    ensureSuccessfulGeminiResponse($response, $statusLine);
    $payload = decodeGeminiListModelsPayload($response);

    $models = extractGenerateContentModelNames($payload);
    $models = array_values(array_unique($models));
    sort($models);

    return $models;
}

/**
 * @return array{0:string,1:string}
 */
function fetchGeminiListModelsResponse(string $url): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 20,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\n",
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        fwrite(STDERR, "Could not reach Gemini ListModels endpoint.\n");
        exit(1);
    }

    return [$response, $http_response_header[0] ?? ''];
}

function ensureSuccessfulGeminiResponse(string $response, string $statusLine): void
{
    if (!preg_match('/\s(\d{3})\s/', $statusLine, $statusMatches)) {
        fwrite(STDERR, "Could not determine Gemini ListModels response status.\n");
        exit(1);
    }

    $statusCode = (int) $statusMatches[1];
    if ($statusCode < 200 || $statusCode >= 300) {
        fwrite(STDERR, "Gemini ListModels failed with HTTP " . $statusCode . ".\n");
        fwrite(STDERR, $response . "\n");
        exit(1);
    }
}

/**
 * @return array<string,mixed>
 */
function decodeGeminiListModelsPayload(string $response): array
{
    $payload = json_decode($response, true);
    if (!is_array($payload)) {
        fwrite(STDERR, "Gemini ListModels returned invalid JSON.\n");
        exit(1);
    }

    return $payload;
}

/**
 * @param array<string,mixed> $payload
 * @return array<int,string>
 */
function extractGenerateContentModelNames(array $payload): array
{
    $models = [];
    foreach (($payload['models'] ?? []) as $model) {
        if (!is_array($model)) {
            continue;
        }

        $name = isset($model['name']) ? (string) $model['name'] : '';
        $methods = isset($model['supportedGenerationMethods']) && is_array($model['supportedGenerationMethods'])
            ? $model['supportedGenerationMethods']
            : [];

        if ($name === '' || !in_array('generateContent', $methods, true)) {
            continue;
        }

        $shortName = preg_replace('#^models/#', '', $name);
        if (!is_string($shortName) || $shortName === '') {
            continue;
        }

        $models[] = $shortName;
    }

    return $models;
}

/**
 * @param array<int,string> $errors
 * @param array<int,string> $warnings
 */
function renderMessages(array $errors, array $warnings): void
{
    foreach ($warnings as $warning) {
        fwrite(STDOUT, "[warn] " . $warning . "\n");
    }

    foreach ($errors as $error) {
        fwrite(STDERR, "[error] " . $error . "\n");
    }
}
