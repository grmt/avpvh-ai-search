<?php
/**
 * Local-only review server for generated Skype chat Markdown.
 */

declare(strict_types=1);

const GEWEB_CHAT_REVIEW_MAX_RESULTS = 100;

function geweb_chat_review_root(): string {
    return dirname(__DIR__) . '/.chat-review/chats';
}

/**
 * @return array<string,mixed>
 */
function geweb_chat_review_manifest(): array {
    static $manifest = null;
    if (is_array($manifest)) {
        return $manifest;
    }
    $json = file_get_contents(geweb_chat_review_root() . '/manifest.json');
    $decoded = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($decoded)) {
        throw new RuntimeException('The chat review manifest is unavailable or invalid.');
    }
    $manifest = $decoded;
    return $manifest;
}

/**
 * @param mixed $payload
 */
function geweb_chat_review_json($payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

function geweb_chat_review_serve_asset(string $path, string $mimeType): void {
    if (!is_file($path) || !is_readable($path)) {
        http_response_code(404);
        return;
    }
    header('Content-Type: ' . $mimeType);
    readfile($path);
}

function geweb_chat_review_query(string $name): string {
    return isset($_GET[$name]) ? trim((string) $_GET[$name]) : '';
}

/**
 * @return array<string,array<string,mixed>>
 */
function geweb_chat_review_documents(): array {
    $documents = [];
    foreach ((array) (geweb_chat_review_manifest()['files'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $path = (string) ($item['path'] ?? '');
        if ($path !== '') {
            $documents[$path] = $item;
        }
    }
    return $documents;
}

function geweb_chat_review_document_path(string $relativePath): ?string {
    if (!array_key_exists($relativePath, geweb_chat_review_documents())) {
        return null;
    }
    $root = realpath(geweb_chat_review_root());
    $path = realpath(geweb_chat_review_root() . '/' . $relativePath);
    if (!is_string($root) || !is_string($path) || strpos($path, $root . DIRECTORY_SEPARATOR) !== 0) {
        return null;
    }
    return is_file($path) && is_readable($path) ? $path : null;
}

function geweb_chat_review_snippet(string $content, int $offset, int $queryLength): string {
    $start = max(0, $offset - 100);
    $length = max(240, $queryLength + 200);
    $snippet = preg_replace('/\s+/u', ' ', substr($content, $start, $length)) ?? '';
    return ($start > 0 ? '…' : '') . trim($snippet) . ($start + $length < strlen($content) ? '…' : '');
}

/**
 * @param array<string,mixed> $item
 * @return array<string,mixed>|null
 */
function geweb_chat_review_match(array $item, string $query): ?array {
    $path = (string) ($item['path'] ?? '');
    $documentPath = geweb_chat_review_document_path($path);
    if ($documentPath === null) {
        return null;
    }
    $content = file_get_contents($documentPath);
    if (!is_string($content)) {
        return null;
    }
    $metadata = implode(' ', [
        (string) ($item['title'] ?? ''),
        (string) ($item['display_title'] ?? ''),
        ($item['visibility'] ?? 'internal') === 'private' ? 'private privé' : 'internal intern',
        implode(' ', (array) ($item['participants'] ?? [])),
        $path,
    ]);
    $metadataOffset = stripos($metadata, $query);
    $contentOffset = stripos($content, $query);
    if ($metadataOffset === false && $contentOffset === false) {
        return null;
    }
    $item['snippet'] = $contentOffset === false
        ? 'Matched title, participant, or filename.'
        : geweb_chat_review_snippet($content, $contentOffset, strlen($query));
    return $item;
}

/**
 * @return array<int,array<string,mixed>>
 */
function geweb_chat_review_search(string $query): array {
    $results = [];
    foreach (geweb_chat_review_documents() as $item) {
        $match = geweb_chat_review_match($item, $query);
        if ($match !== null) {
            $results[] = $match;
        }
        if (count($results) >= GEWEB_CHAT_REVIEW_MAX_RESULTS) {
            break;
        }
    }
    return $results;
}

function geweb_chat_review_render_app(): void {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Chat review</title>
    <link rel="stylesheet" href="/review.css">
</head>
<body>
    <aside class="sidebar">
        <header>
            <p class="eyebrow">Lokale privéweergave</p>
            <h1>Chat review</h1>
            <form id="search-form" role="search">
                <input id="search" type="search" placeholder="Zoek tekst, titel of deelnemer…" autocomplete="off">
                <button type="submit">Zoeken</button>
            </form>
            <p id="summary" class="summary">Documenten laden…</p>
        </header>
        <ol id="documents" class="documents" aria-label="Chatdocumenten"></ol>
    </aside>
    <main class="viewer">
        <div id="empty-state" class="empty-state">
            <h2>Kies links een chat</h2>
            <p>Zoeken doorzoekt ook de volledige inhoud van alle gesplitste documenten.</p>
        </div>
        <article id="document" hidden>
            <header class="document-header">
                <div>
                    <p id="document-participants" class="eyebrow"></p>
                    <h2 id="document-title"></h2>
                    <p id="document-meta" class="summary"></p>
                </div>
                <button id="toggle-raw" type="button">Ruwe Markdown</button>
            </header>
            <div id="rendered" class="rendered"></div>
            <pre id="raw" class="raw" hidden></pre>
        </article>
    </main>
    <script src="/markdown-renderer.js"></script>
    <script src="/review.js"></script>
</body>
</html>
HTML;
}

function geweb_chat_review_route_api(string $path): bool {
    if ($path === '/api/documents') {
        geweb_chat_review_json(array_values(geweb_chat_review_documents()));
        return true;
    }
    if ($path === '/api/search') {
        $query = geweb_chat_review_query('q');
        geweb_chat_review_json($query === '' ? [] : geweb_chat_review_search($query));
        return true;
    }
    if ($path !== '/api/document') {
        return false;
    }
    $relativePath = geweb_chat_review_query('path');
    $documentPath = geweb_chat_review_document_path($relativePath);
    if ($documentPath === null) {
        geweb_chat_review_json(['error' => 'Document not found.'], 404);
        return true;
    }
    $content = file_get_contents($documentPath);
    geweb_chat_review_json([
        'document' => geweb_chat_review_documents()[$relativePath],
        'content' => is_string($content) ? $content : '',
    ]);
    return true;
}

header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; img-src 'self' data:; base-uri 'none'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
if (geweb_chat_review_route_api($path)) {
    return;
}
if ($path === '/review.css') {
    geweb_chat_review_serve_asset(__DIR__ . '/chat-review.css', 'text/css; charset=utf-8');
    return;
}
if ($path === '/review.js') {
    geweb_chat_review_serve_asset(__DIR__ . '/chat-review.js', 'application/javascript; charset=utf-8');
    return;
}
if ($path === '/markdown-renderer.js') {
    geweb_chat_review_serve_asset(dirname(__DIR__) . '/assets/js/markdown-renderer.js', 'application/javascript; charset=utf-8');
    return;
}
if ($path === '/') {
    geweb_chat_review_render_app();
    return;
}
http_response_code(404);
