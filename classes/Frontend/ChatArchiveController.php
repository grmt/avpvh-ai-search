<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

final class ChatArchiveController {
    private const NONCE_ACTION = 'geweb_chat_archive';
    private ChatArchiveRepository $repository;
    private ChatMeilisearchService $searchService;

    public function __construct(ChatArchiveRepository $repository, ChatMeilisearchService $searchService) {
        $this->repository = $repository;
        $this->searchService = $searchService;
    }

    public function registerHooks(): void {
        add_shortcode('geweb_chat_archive', [$this, 'renderShortcode']);
        add_action('wp_ajax_geweb_chat_archive_list', [$this, 'ajaxList']);
        add_action('wp_ajax_geweb_chat_archive_search', [$this, 'ajaxSearch']);
        add_action('wp_ajax_geweb_chat_archive_document', [$this, 'ajaxDocument']);
    }

    public function renderShortcode(): string {
        if (!is_user_logged_in() || !current_user_can('read')) {
            return '<p>Log in om de chatbibliotheek te bekijken.</p>';
        }
        if (!$this->repository->isAvailable()) {
            return '<p>De chatbibliotheek is nog niet beschikbaar.</p>';
        }
        $this->enqueueAssets();
        return $this->renderShell();
    }

    public function ajaxList(): void {
        $this->authorize();
        wp_send_json_success(array_map([$this, 'publicDocument'], $this->repository->getDocuments($this->canViewPrivate())));
    }

    public function ajaxSearch(): void {
        $this->authorize();
        $query = sanitize_text_field((string) wp_unslash($_POST['query'] ?? ''));
        if ($query === '') {
            wp_send_json_success([]);
        }
        $results = $this->searchInternal($query);
        if ($this->canViewPrivate()) {
            $results = $this->mergePrivateResults($results, $query);
        }
        wp_send_json_success(array_slice($results, 0, 100));
    }

    public function ajaxDocument(): void {
        $this->authorize();
        $path = sanitize_text_field((string) wp_unslash($_POST['path'] ?? ''));
        $document = $this->repository->getDocument($path, $this->canViewPrivate());
        if ($document === null) {
            wp_send_json_error(['message' => 'Chatdocument niet gevonden.'], 404);
        }
        wp_send_json_success([
            'document' => $this->publicDocument($document),
            'content' => $this->repository->readDocument($document),
        ]);
    }

    private function authorize(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!is_user_logged_in() || !current_user_can('read')) {
            wp_send_json_error(['message' => 'Onvoldoende rechten.'], 403);
        }
    }

    private function canViewPrivate(): bool {
        return current_user_can('manage_options');
    }

    private function enqueueAssets(): void {
        wp_enqueue_style(
            'geweb-ai-search-chat-archive',
            AVPVH_SRCH_URL . 'assets/css/chat-archive.css',
            [],
            AssetVersion::forRelativePath('assets/css/chat-archive.css')
        );
        wp_enqueue_script(
            'geweb-ai-search-markdown-renderer',
            AVPVH_SRCH_URL . 'assets/js/markdown-renderer.js',
            [],
            AssetVersion::forRelativePath('assets/js/markdown-renderer.js'),
            true
        );
        wp_enqueue_script(
            'geweb-ai-search-chat-archive',
            AVPVH_SRCH_URL . 'assets/js/chat-archive.js',
            ['geweb-ai-search-markdown-renderer'],
            AssetVersion::forRelativePath('assets/js/chat-archive.js'),
            true
        );
        wp_localize_script('geweb-ai-search-chat-archive', 'gewebChatArchive', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'canViewPrivate' => $this->canViewPrivate(),
        ]);
    }

    private function renderShell(): string {
        return '<div class="geweb-chat-archive">'
            . '<aside class="geweb-chat-sidebar">'
            . '<form class="geweb-chat-search" role="search">'
            . '<label for="geweb-chat-query">Zoek in chats</label>'
            . '<div><input id="geweb-chat-query" type="search" placeholder="Tekst, titel of deelnemer…" autocomplete="off">'
            . '<button type="submit">Zoeken</button></div></form>'
            . '<p class="geweb-chat-summary">Chats laden…</p>'
            . '<ol class="geweb-chat-documents" aria-label="Chatdocumenten"></ol>'
            . '</aside><section class="geweb-chat-viewer">'
            . '<div class="geweb-chat-empty"><h2>Kies een chat</h2><p>Je kunt zoeken door de volledige inhoud.</p></div>'
            . '<article class="geweb-chat-document" hidden><header><div><p class="geweb-chat-participants"></p>'
            . '<h2 class="geweb-chat-title"></h2><p class="geweb-chat-meta"></p></div>'
            . '<button class="geweb-chat-toggle" type="button">Ruwe Markdown</button></header>'
            . '<div class="geweb-chat-rendered"></div><pre class="geweb-chat-raw" hidden></pre></article>'
            . '</section></div>';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function searchInternal(string $query): array {
        try {
            $results = $this->searchService->search($query);
            if (is_array($results)) {
                return $results;
            }
        } catch (\Throwable $exception) {
            error_log('geweb-ai-search: chat Meilisearch fallback: ' . $exception->getMessage());
        }
        return $this->repository->searchLocal($query, false);
    }

    /**
     * @param array<int,array<string,mixed>> $results
     * @return array<int,array<string,mixed>>
     */
    private function mergePrivateResults(array $results, string $query): array {
        $seen = [];
        foreach ($results as $result) {
            $seen[(string) ($result['path'] ?? '')] = true;
        }
        foreach ($this->repository->searchLocal($query, true) as $result) {
            $path = (string) ($result['path'] ?? '');
            if (($result['visibility'] ?? 'internal') === 'private' && !isset($seen[$path])) {
                $results[] = $result;
            }
        }
        return $results;
    }

    /**
     * @param array<string,mixed> $document
     * @return array<string,mixed>
     */
    private function publicDocument(array $document): array {
        unset($document['file_path']);
        return $document;
    }
}
