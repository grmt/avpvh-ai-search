<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Renders and controls the frontend AI workspace.
 */
class FrontendAiWorkspaceController {
    /**
     * @var callable
     */
    private $getTabUrl;
    /**
     * @var callable
     */
    private $getFrontendAiChatModelConfig;
    /**
     * @var callable
     */
    private $getFrontendPromptDescriptors;

    private bool $frontendAiPageModalRendered = false;
    private bool $shortcodePageViewActive = false;
    /**
     * @var array<string,mixed>
     */
    private array $renderOverrides = [];

    /**
     * @param callable $getTabUrl
     * @param callable $getFrontendAiChatModelConfig
     * @param callable $getFrontendPromptDescriptors
     */
    public function __construct(callable $getTabUrl, callable $getFrontendAiChatModelConfig, callable $getFrontendPromptDescriptors) {
        $this->getTabUrl = $getTabUrl;
        $this->getFrontendAiChatModelConfig = $getFrontendAiChatModelConfig;
        $this->getFrontendPromptDescriptors = $getFrontendPromptDescriptors;
    }

    public function enqueueScripts(): void {
        $locale = function_exists('determine_locale') ? (string) determine_locale() : get_locale();
        $searchWithAiLabel = $this->getSearchWithAiLabel($locale);

        wp_enqueue_style(
            'geweb-ai-search-page-shell',
            AVPVH_SRCH_URL . 'assets/css/page-shell.css',
            [],
            AssetVersion::forRelativePath('assets/css/page-shell.css')
        );

        wp_enqueue_script(
            'geweb-ai-search-shared',
            AVPVH_SRCH_URL . 'assets/js/ai-shared.js',
            ['jquery'],
            AssetVersion::forRelativePath('assets/js/ai-shared.js'),
            true
        );

        wp_localize_script('geweb-ai-search-shared', 'geweb_aisearch', array_merge($this->buildFrontendLauncherConfig($searchWithAiLabel), [
            'ajax_url' => admin_url('admin-ajax.php'),
            'site_url' => home_url('/'),
            'search_nonce' => wp_create_nonce('geweb_ai_search_nonce'),
        ]));

        wp_enqueue_script(
            'geweb-ai-search-page-match',
            AVPVH_SRCH_URL . 'assets/js/ai-page-match.js',
            ['geweb-ai-search-shared'],
            AssetVersion::forRelativePath('assets/js/ai-page-match.js'),
            true
        );

        if (!$this->shouldLoadFullFrontendWorkspace()) {
            wp_enqueue_script(
                'geweb-ai-search-launcher',
                AVPVH_SRCH_URL . 'assets/launcher.js',
                ['jquery'],
                AssetVersion::forRelativePath('assets/launcher.js'),
                true
            );

            wp_localize_script('geweb-ai-search-launcher', 'geweb_aisearch', $this->buildFrontendLauncherConfig($searchWithAiLabel));
            return;
        }

        $provider = ProviderFactory::make();
        $frontendModelConfig = ($this->getFrontendAiChatModelConfig)($provider);
        $models = is_array($frontendModelConfig['models'] ?? null) ? $frontendModelConfig['models'] : [];
        $selectedModel = is_string($frontendModelConfig['selected_model'] ?? null) ? $frontendModelConfig['selected_model'] : '';
        if ($selectedModel !== '' && !in_array($selectedModel, $models, true) && method_exists($provider, 'getDefaultModel')) {
            array_unshift($models, $selectedModel);
            $models = array_values(array_unique($models));
        }

        wp_enqueue_script(
            'geweb-ai-search-markdown-renderer',
            AVPVH_SRCH_URL . 'assets/js/markdown-renderer.js',
            [],
            AssetVersion::forRelativePath('assets/js/markdown-renderer.js'),
            true
        );

        wp_enqueue_script(
            'geweb-ai-search-modal',
            AVPVH_SRCH_URL . 'assets/js/ai-modal.js',
            ['jquery', 'geweb-ai-search-shared'],
            AssetVersion::forRelativePath('assets/js/ai-modal.js'),
            true
        );

        wp_enqueue_script(
            'geweb-ai-search-sources-utils',
            AVPVH_SRCH_URL . 'assets/js/ai-sources-utils.js',
            ['geweb-ai-search-shared'],
            AssetVersion::forRelativePath('assets/js/ai-sources-utils.js'),
            true
        );

        wp_enqueue_script(
            'geweb-ai-search-sources-details',
            AVPVH_SRCH_URL . 'assets/js/ai-sources-details.js',
            ['jquery', 'geweb-ai-search-shared', 'geweb-ai-search-sources-utils'],
            AssetVersion::forRelativePath('assets/js/ai-sources-details.js'),
            true
        );

        wp_enqueue_script(
            'geweb-ai-search-sources',
            AVPVH_SRCH_URL . 'assets/js/ai-sources.js',
            ['jquery', 'geweb-ai-search-markdown-renderer', 'geweb-ai-search-shared', 'geweb-ai-search-sources-utils', 'geweb-ai-search-sources-details'],
            AssetVersion::forRelativePath('assets/js/ai-sources.js'),
            true
        );

        wp_enqueue_script(
            'geweb-ai-search',
            AVPVH_SRCH_URL . 'assets/script.js',
            ['jquery', 'geweb-ai-search-shared', 'geweb-ai-search-page-match', 'geweb-ai-search-modal', 'geweb-ai-search-sources'],
            AssetVersion::forRelativePath('assets/script.js'),
            true
        );

        wp_enqueue_style(
            'geweb-ai-search-workspace-layout',
            AVPVH_SRCH_URL . 'assets/css/workspace-layout.css',
            ['geweb-ai-search-page-shell'],
            AssetVersion::forRelativePath('assets/css/workspace-layout.css')
        );

        wp_enqueue_style(
            'geweb-ai-search-workspace-panels',
            AVPVH_SRCH_URL . 'assets/css/workspace-panels.css',
            ['geweb-ai-search-workspace-layout'],
            AssetVersion::forRelativePath('assets/css/workspace-panels.css')
        );

        wp_enqueue_style(
            'geweb-ai-search-workspace-content',
            AVPVH_SRCH_URL . 'assets/css/workspace-content.css',
            ['geweb-ai-search-workspace-panels'],
            AssetVersion::forRelativePath('assets/css/workspace-content.css')
        );

        wp_localize_script('geweb-ai-search', 'geweb_aisearch', array_merge($this->buildFrontendLauncherConfig($searchWithAiLabel), [
            'ajax_url' => admin_url('admin-ajax.php'),
            'site_url' => home_url('/'),
            'models' => array_values($models),
            'selected_model' => $selectedModel,
            'gemini_timeout_flash_seconds' => (int) get_option(Gemini::OPTION_TIMEOUT_FLASH, Gemini::DEFAULT_HTTP_TIMEOUT_SECONDS),
            'gemini_timeout_pro_seconds' => (int) get_option(Gemini::OPTION_TIMEOUT_PRO, Gemini::DEFAULT_PRO_HTTP_TIMEOUT_SECONDS),
            'frontend_ai_request_timeout_seconds' => $this->getFrontendAiRequestTimeoutSeconds($selectedModel),
            'prompt_descriptors' => ($this->getFrontendPromptDescriptors)($provider, $models, $selectedModel),
            'frontend_ai_interface' => FrontendAiContext::getFrontendAiInterface(),
            'frontend_ai_page_url' => FrontendAiContext::getCurrentFrontendAiPageUrl(),
            'frontend_ai_exit_url' => FrontendAiContext::getFrontendAiExitUrl(),
            'frontend_ai_manage_conversations_url' => current_user_can('manage_options') ? ($this->getTabUrl)('conversations') : '',
            'frontend_ai_manage_documents_url' => current_user_can('manage_options') ? ($this->getTabUrl)('documents') : '',
            'frontend_ai_manage_pages_url' => current_user_can('edit_pages') ? admin_url('edit.php?post_type=page') : '',
            'frontend_ai_edit_post_url' => current_user_can('edit_pages') ? admin_url('post.php') : '',
            'current_scope_key' => UserScope::getCurrentUserScopeStorageKey(),
            'is_frontend_ai_page' => FrontendAiContext::isFrontendAiPageRequest($this->shortcodePageViewActive),
            'frontend_ai_conversation_id' => FrontendAiContext::getRequestedFrontendConversationId(),
            'frontend_ai_initial_query' => FrontendAiContext::getRequestedFrontendQuery(),
            'conversation_trim_message_limit' => FrontendAiContext::getConversationTrimMessageLimit(),
            'conversation_trim_char_limit' => FrontendAiContext::getConversationTrimCharLimit(),
            'local_conversation_archive_limit' => FrontendAiContext::getLocalConversationArchiveLimit(),
            'i18n' => [
                'openAiSearch' => __('Open AI Search', 'avpvh-ai-search'),
                'askAi' => __('Ask AI', 'avpvh-ai-search'),
                'searchWithAi' => $searchWithAiLabel,
                'thinking' => __('Thinking...', 'avpvh-ai-search'),
                'thoughtProcess' => __('Denkproces', 'avpvh-ai-search'),
                'thoughtProcessPending' => __('Denkproces wordt opgebouwd...', 'avpvh-ai-search'),
                'couldNotStart' => __('Could not start the AI search. Please try again.', 'avpvh-ai-search'),
                'connectionError' => __('Connection error. Please try again.', 'avpvh-ai-search'),
                'requestTimedOut' => __('The AI request timed out. Please try again.', 'avpvh-ai-search'),
                'requestTimedOutElapsed' => __('Elapsed time', 'avpvh-ai-search'),
                'answerError' => __('Error: Unable to get response', 'avpvh-ai-search'),
                'responseDetails' => __('Response details', 'avpvh-ai-search'),
                'requestMetaTitle' => __('Request context', 'avpvh-ai-search'),
                'clickAnswerForDetails' => __('Click the answer to show response details.', 'avpvh-ai-search'),
                'hideDetails' => __('Hide details', 'avpvh-ai-search'),
                'showDetails' => __('Show details', 'avpvh-ai-search'),
                'earlierTrimmed' => __('Earlier messages were trimmed to keep the chat context compact.', 'avpvh-ai-search'),
                'contextSummaryCollapsed' => __('Compacted context summary used. Click to expand.', 'avpvh-ai-search'),
                'noChatsYet' => __('No chats yet.', 'avpvh-ai-search'),
                'copyAnswer' => __('Copy answer', 'avpvh-ai-search'),
                'retryAnswer' => __('Retry with longer timeout', 'avpvh-ai-search'),
                'copyConversation' => __('Copy chat', 'avpvh-ai-search'),
                'temporaryPrompt' => __('Temporary chat prompt', 'avpvh-ai-search'),
                'temporaryPromptActive' => __('Temporary prompt', 'avpvh-ai-search'),
                'temporaryModelActive' => __('Temporary model', 'avpvh-ai-search'),
                'temporaryPromptPrefix' => __('Temporary override of', 'avpvh-ai-search'),
                'temporaryPromptPlaceholder' => __('Optional prompt override for this question only...', 'avpvh-ai-search'),
                'toggleTemporaryPrompt' => __('Toggle temporary prompt', 'avpvh-ai-search'),
                'composerSettings' => __('Settings', 'avpvh-ai-search'),
                'composerSettingsTitle' => __('Next question settings', 'avpvh-ai-search'),
                'composerNextMessageOnly' => __('Applies to the next question only.', 'avpvh-ai-search'),
                'composerAppliesNextQuestions' => __('Applies to the next questions until you reset it.', 'avpvh-ai-search'),
                'composerReset' => __('Reset', 'avpvh-ai-search'),
                'composerClose' => __('Close settings', 'avpvh-ai-search'),
                'composerUseDefaultModel' => __('Use default model', 'avpvh-ai-search'),
                'composerUseDefaultPrompt' => __('Use default prompt', 'avpvh-ai-search'),
                'composerEditPrompt' => __('Edit prompt', 'avpvh-ai-search'),
                'composerHidePromptEditor' => __('Hide prompt editor', 'avpvh-ai-search'),
                'composerTemporaryOverride' => __('Defaults overridden', 'avpvh-ai-search'),
                'composerPromptLabel' => __('Prompt', 'avpvh-ai-search'),
                'composerModelLabel' => __('Model', 'avpvh-ai-search'),
                'composerPreviousQuestion' => __('Previous question', 'avpvh-ai-search'),
                'composerNextQuestion' => __('Next question', 'avpvh-ai-search'),
                'copied' => __('Copied', 'avpvh-ai-search'),
                'copyFailed' => __('Could not copy', 'avpvh-ai-search'),
                'excludeSourceTemporarily' => __('Temporarily exclude', 'avpvh-ai-search'),
                'excludeSourceTemporarilyTitle' => __('Temporarily exclude this source from the next question', 'avpvh-ai-search'),
                'includeSourceAgain' => __('Use again', 'avpvh-ai-search'),
                'includeSourceAgainTitle' => __('Allow this source again for the next question', 'avpvh-ai-search'),
                'useAllSourcesAgain' => __('Use all sources again', 'avpvh-ai-search'),
                'oneSourceTemporarilyExcluded' => __('1 source temporarily excluded for the next question.', 'avpvh-ai-search'),
                'multipleSourcesTemporarilyExcluded' => __('%d sources temporarily excluded for the next question.', 'avpvh-ai-search'),
                'savedChat' => __('Saved chat', 'avpvh-ai-search'),
                'untitledConversation' => __('Untitled chat', 'avpvh-ai-search'),
                'noSourcesYet' => __('No source links yet.', 'avpvh-ai-search'),
                'renameConversation' => __('Rename chat', 'avpvh-ai-search'),
                'removeConversationConfirm' => __('Remove this chat from the current search context?', 'avpvh-ai-search'),
                'mentionedInAnswer' => __('Mentioned in answer', 'avpvh-ai-search'),
                'newChat' => __('New chat', 'avpvh-ai-search'),
                'linksToPages' => __('Links to pages and documents used in the answer.', 'avpvh-ai-search'),
                'openPagesListing' => __('Open pages listing', 'avpvh-ai-search'),
                'openDocumentsListing' => __('Open documents listing', 'avpvh-ai-search'),
                'closeSourceContext' => __('Close source context', 'avpvh-ai-search'),
                'showResults' => __('Show results', 'avpvh-ai-search'),
                'hideResults' => __('Hide results', 'avpvh-ai-search'),
                'manageConversations' => __('Manage chats', 'avpvh-ai-search'),
                'modelLabel' => __('Model', 'avpvh-ai-search'),
                'searchResultsIntro' => __('Search this site here to show regular WordPress results without leaving the AI workspace.', 'avpvh-ai-search'),
                'searchResultsLabel' => __('Search results', 'avpvh-ai-search'),
                'mobileChatsTab' => __('History', 'avpvh-ai-search'),
                'mobileChatTab' => __('AI chat', 'avpvh-ai-search'),
                'mobileSourcesTab' => __('Sources', 'avpvh-ai-search'),
            ],
        ]));
    }

    private function getSearchWithAiLabel(string $locale): string {
        $normalizedLocale = strtolower(str_replace('-', '_', $locale));
        if (str_starts_with($normalizedLocale, 'nl')) {
            return 'Zoeken met AI';
        }

        if (str_starts_with($normalizedLocale, 'en_gb') || str_starts_with($normalizedLocale, 'en_us') || str_starts_with($normalizedLocale, 'en')) {
            return 'AI search';
        }

        return __('AI search', 'avpvh-ai-search');
    }

    /**
     * @param array<string,mixed> $atts
     */
    public function renderFrontendAiSearchShortcode(array $atts = []): string {
        if (is_admin()) {
            return '';
        }

        $atts = shortcode_atts([
            'title' => '',
            'search_results' => 'show',
            'search_results_height' => '0',
            'manage_link' => 'show',
        ], $atts, 'avpvh_ai_search');

        $searchResults = sanitize_key((string) $atts['search_results']);
        $manageLink = sanitize_key((string) $atts['manage_link']);
        $initialHeight = is_numeric($atts['search_results_height']) ? (int) $atts['search_results_height'] : 0;
        $initialHeight = max(0, min(100, $initialHeight));

        if ($searchResults === 'hide') {
            $initialHeight = 0;
        }

        $this->shortcodePageViewActive = true;
        $this->renderOverrides = [
            'title' => sanitize_text_field((string) $atts['title']),
            'search_results_initial_height' => $initialHeight,
            'show_manage_link' => $manageLink !== 'hide',
        ];

        ob_start();
        $this->renderModals();
        $output = (string) ob_get_clean();
        $this->renderOverrides = [];

        return $output;
    }

    public function maybeRemoveFrontendAdminBarSearch(\WP_Admin_Bar $adminBar): void {
        if (is_admin() || !FrontendAiContext::isFrontendAiPageRequest($this->shortcodePageViewActive)) {
            return;
        }

        $adminBar->remove_node('search');
    }

    public function renderModals(): void {
        if (!$this->shouldLoadFullFrontendWorkspace()) {
            return;
        }

        $isFrontendAiPage = FrontendAiContext::isFrontendAiPageRequest($this->shortcodePageViewActive);
        $provider = ProviderFactory::make();
        $frontendModelConfig = ($this->getFrontendAiChatModelConfig)($provider);
        $models = is_array($frontendModelConfig['models'] ?? null) ? $frontendModelConfig['models'] : [];
        $selectedModel = is_string($frontendModelConfig['selected_model'] ?? null) ? $frontendModelConfig['selected_model'] : '';
        $promptDescriptors = ($this->getFrontendPromptDescriptors)($provider, $models, $selectedModel);
        $currentPromptDescriptor = $promptDescriptors[$selectedModel] ?? [];
        if (empty($currentPromptDescriptor)) {
            $fallbackPromptDescriptor = reset($promptDescriptors);
            $currentPromptDescriptor = is_array($fallbackPromptDescriptor)
                ? $fallbackPromptDescriptor
                : ['name' => __('Built-in prompt', 'avpvh-ai-search'), 'instruction' => ''];
        }
        $currentPromptName = trim((string) ($currentPromptDescriptor['name'] ?? ''));
        if ($currentPromptName === '') {
            $currentPromptName = __('Built-in prompt', 'avpvh-ai-search');
        }
        $currentPromptInstruction = (string) ($currentPromptDescriptor['instruction'] ?? '');

        if ($isFrontendAiPage && $this->frontendAiPageModalRendered) {
            return;
        }

        if ($isFrontendAiPage) {
            $this->frontendAiPageModalRendered = true;
        }

        $tagName = $isFrontendAiPage ? 'section' : 'dialog';
        $pageViewClass = $isFrontendAiPage ? ' geweb-aisearch-modal-window--page' : '';
        $pageViewAttribute = $isFrontendAiPage ? ' data-geweb-page-view="1"' : '';
        $title = (string) apply_filters('geweb_aisearch_ai_modal_title', 'AI Assistant');
        if (isset($this->renderOverrides['title']) && trim((string) $this->renderOverrides['title']) !== '') {
            $title = (string) $this->renderOverrides['title'];
        }
        $showManageLink = !isset($this->renderOverrides['show_manage_link']) || !empty($this->renderOverrides['show_manage_link']);
        ?>
        <<?php echo esc_html($tagName); ?> id="geweb-ai-modal" class="geweb-aisearch-modal-window geweb-aisearch-modal-window--<?php echo esc_attr(FrontendAiContext::getFrontendAiInterface()); ?><?php echo esc_attr($pageViewClass); ?>" aria-label="<?php echo esc_attr($title); ?>"<?php echo $pageViewAttribute; ?>>
            <?php if (!$isFrontendAiPage): ?>
                <div class="modal-header">
                    <strong class="ai-assistant-title"><?php echo esc_html($title); ?></strong>
                    <button type="button" class="close" aria-label="<?php echo esc_attr__('Close AI assistant', 'avpvh-ai-search'); ?>"></button>
                </div>
            <?php endif; ?>
            <?php if ($isFrontendAiPage): ?>
                <?php $this->renderFrontendAiPageToolbar(); ?>
                <?php $this->renderFrontendAiSearchResultsPanel(); ?>
            <?php endif; ?>
            <div class="geweb-ai-workspace" data-mobile-pane="main">
                <aside class="geweb-ai-sidebar" data-mobile-pane="left" tabindex="-1" aria-label="<?php echo esc_attr__('Chat panel', 'avpvh-ai-search'); ?>">
                    <div class="geweb-ai-overview-header">
                        <div class="geweb-ai-panel-heading">
                            <div class="geweb-ai-panel-heading-main">
                                <div class="geweb-ai-panel-title geweb-ai-panel-title--inline"><?php echo esc_html__('Chat', 'avpvh-ai-search'); ?></div>
                                <div class="geweb-ai-panel-heading-actions geweb-ai-panel-heading-actions--conversation">
                                    <button type="button" class="geweb-ai-new-conversation geweb-ai-overview-action-button geweb-ai-overview-action-button--icon-first" aria-label="<?php echo esc_attr__('New chat', 'avpvh-ai-search'); ?>" title="<?php echo esc_attr__('New chat', 'avpvh-ai-search'); ?>">
                                        <span class="geweb-ai-overview-action-icon" aria-hidden="true">+</span>
                                        <span class="geweb-ai-overview-action-label"><?php echo esc_html__('New', 'avpvh-ai-search'); ?></span>
                                    </button>
                                    <?php if ($showManageLink && current_user_can('manage_options')): ?>
                                        <a class="geweb-ai-overview-action-button geweb-ai-overview-action-button--icon-first" href="<?php echo esc_url(($this->getTabUrl)('conversations')); ?>" aria-label="<?php echo esc_attr__('Manage chats', 'avpvh-ai-search'); ?>" title="<?php echo esc_attr__('Manage chats', 'avpvh-ai-search'); ?>">
                                            <span class="geweb-ai-overview-action-icon" aria-hidden="true">⚙</span>
                                            <span class="geweb-ai-overview-action-label"><?php echo esc_html__('Manage', 'avpvh-ai-search'); ?></span>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <button type="button" class="geweb-ai-icon-button geweb-ai-panel-collapse" data-panel-toggle="left" aria-expanded="true" aria-label="<?php echo esc_attr__('Collapse chats panel', 'avpvh-ai-search'); ?>" title="<?php echo esc_attr__('Collapse chats panel', 'avpvh-ai-search'); ?>">
                                <span class="geweb-ai-panel-collapse-icon" aria-hidden="true">◀</span>
                            </button>
                        </div>
                    </div>
                    <div class="geweb-ai-current-conversation">
                        <div class="geweb-ai-current-conversation-header">
                            <div class="geweb-ai-current-conversation-label"><?php echo esc_html__('Current chat', 'avpvh-ai-search'); ?></div>
                            <div class="geweb-ai-current-conversation-actions">
                                <button type="button" class="geweb-ai-icon-button geweb-ai-secondary-button geweb-ai-secondary-button--icon-first geweb-ai-current-conversation-action" id="geweb-ai-copy-conversation" aria-label="<?php echo esc_attr__('Copy chat', 'avpvh-ai-search'); ?>" title="<?php echo esc_attr__('Copy chat', 'avpvh-ai-search'); ?>">
                                    <span class="geweb-ai-overview-action-icon" aria-hidden="true">⧉</span>
                                    <span class="geweb-ai-overview-action-label"><?php echo esc_html__('Copy', 'avpvh-ai-search'); ?></span>
                                </button>
                                <button type="button" class="geweb-ai-icon-button geweb-ai-secondary-button geweb-ai-secondary-button--icon-first geweb-ai-current-conversation-action" id="geweb-ai-rename-conversation" aria-label="<?php echo esc_attr__('Rename chat', 'avpvh-ai-search'); ?>" title="<?php echo esc_attr__('Rename chat', 'avpvh-ai-search'); ?>">
                                    <span class="geweb-ai-overview-action-icon" aria-hidden="true">✎</span>
                                    <span class="geweb-ai-overview-action-label"><?php echo esc_html__('Rename', 'avpvh-ai-search'); ?></span>
                                </button>
                                <button type="button" class="geweb-ai-icon-button geweb-ai-secondary-button geweb-ai-secondary-button--icon-first geweb-ai-current-conversation-action" id="geweb-ai-delete-conversation" aria-label="<?php echo esc_attr__('Remove chat', 'avpvh-ai-search'); ?>" title="<?php echo esc_attr__('Remove chat', 'avpvh-ai-search'); ?>">
                                    <span class="geweb-ai-overview-action-icon" aria-hidden="true">−</span>
                                    <span class="geweb-ai-overview-action-label"><?php echo esc_html__('Remove', 'avpvh-ai-search'); ?></span>
                                </button>
                            </div>
                        </div>
                        <div id="geweb-ai-current-conversation-summary" class="geweb-ai-current-conversation-summary"><?php echo esc_html__('Untitled chat', 'avpvh-ai-search'); ?></div>
                    </div>
                    <div id="geweb-ai-conversation-overview" class="geweb-ai-conversation-overview"></div>
                </aside>
                <div class="geweb-ai-pane-resizer geweb-ai-pane-resizer--left" data-resize-target="left" aria-orientation="vertical" aria-label="<?php echo esc_attr__('Resize chats panel', 'avpvh-ai-search'); ?>"></div>
                <div class="geweb-ai-main-panel" data-mobile-pane="main" tabindex="-1">
                    <div class="answer-box"></div>
                    <div class="question-box">
                        <div class="geweb-ai-question-toolbar">
                            <div class="geweb-ai-question-summary" aria-live="polite">
                                <button type="button" class="geweb-ai-question-summary-item" data-geweb-temp-settings-toggle="1" aria-expanded="false" aria-controls="geweb-ai-temporary-settings-panel" title="<?php echo esc_attr__('Next question settings', 'avpvh-ai-search'); ?>">
                                    <span class="geweb-ai-question-summary-label"><?php echo esc_html__('Model:', 'avpvh-ai-search'); ?></span>
                                    <span id="geweb-ai-current-model-display" class="geweb-ai-question-summary-value"><?php echo esc_html($selectedModel); ?></span>
                                </button>
                                <button type="button" class="geweb-ai-question-summary-item" data-geweb-temp-settings-toggle="1" aria-expanded="false" aria-controls="geweb-ai-temporary-settings-panel" title="<?php echo esc_attr__('Next question settings', 'avpvh-ai-search'); ?>">
                                    <span class="geweb-ai-question-summary-label"><?php echo esc_html__('Prompt:', 'avpvh-ai-search'); ?></span>
                                    <span id="geweb-ai-current-prompt-display" class="geweb-ai-question-summary-value geweb-ai-question-summary-value--prompt" title="<?php echo esc_attr($currentPromptInstruction); ?>"><?php echo esc_html($currentPromptName); ?></span>
                                </button>
                            </div>
                            <div class="geweb-ai-question-toolbar-actions">
                                <button type="button" class="geweb-ai-icon-button" data-geweb-temp-settings-toggle="1" aria-label="<?php echo esc_attr__('Settings', 'avpvh-ai-search'); ?>" title="<?php echo esc_attr__('Change model or prompt', 'avpvh-ai-search'); ?>">
                                    <span aria-hidden="true" class="geweb-ai-icon">⚙</span>
                                </button>
                                <button type="button" class="geweb-ai-icon-button geweb-ai-question-history-button" id="geweb-ai-question-history-prev" aria-label="<?php echo esc_attr__('Previous question', 'avpvh-ai-search'); ?>" title="<?php echo esc_attr__('Previous question', 'avpvh-ai-search'); ?>" disabled>
                                    <span aria-hidden="true">↑</span>
                                </button>
                                <button type="button" class="geweb-ai-icon-button geweb-ai-question-history-button" id="geweb-ai-question-history-next" aria-label="<?php echo esc_attr__('Next question', 'avpvh-ai-search'); ?>" title="<?php echo esc_attr__('Next question', 'avpvh-ai-search'); ?>" disabled>
                                    <span aria-hidden="true">↓</span>
                                </button>
                            </div>
                        </div>
                        <div class="geweb-ai-temporary-settings-panel" id="geweb-ai-temporary-settings-panel" hidden>
                            <div class="geweb-ai-temporary-settings-header">
                                <div class="geweb-ai-temporary-settings-heading">
                                    <div class="geweb-ai-temporary-settings-title"><?php echo esc_html__('Next question settings', 'avpvh-ai-search'); ?></div>
                                </div>
                                <button type="button" class="geweb-ai-icon-button geweb-ai-temporary-settings-info" id="geweb-ai-temporary-settings-info" aria-label="<?php echo esc_attr__('About next question settings', 'avpvh-ai-search'); ?>" data-tooltip="<?php echo esc_attr__('Applies to the next questions until you reset it.', 'avpvh-ai-search'); ?>">
                                    <span aria-hidden="true">ⓘ</span>
                                </button>
                                <button type="button" class="geweb-ai-icon-button geweb-ai-secondary-button geweb-ai-secondary-button--icon-only geweb-ai-temporary-settings-close" id="geweb-ai-close-temp-settings" aria-label="<?php echo esc_attr__('Close settings', 'avpvh-ai-search'); ?>" title="<?php echo esc_attr__('Close settings', 'avpvh-ai-search'); ?>">×</button>
                            </div>
                            <?php if (!empty($models)): ?>
                                <div class="geweb-ai-temporary-settings-row">
                                    <label for="geweb-ai-model-selector" class="geweb-ai-model-selector-label"><?php echo esc_html__('Model', 'avpvh-ai-search'); ?></label>
                                    <div class="geweb-ai-temporary-settings-controls">
                                        <select id="geweb-ai-model-selector" class="geweb-ai-model-selector" disabled>
                                            <?php foreach ($models as $model): ?>
                                                <option value="<?php echo esc_attr($model); ?>" <?php selected($selectedModel, $model); ?>><?php echo esc_html($model); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="geweb-ai-icon-button geweb-ai-secondary-button geweb-ai-temporary-reset-button geweb-ai-temporary-inline-action geweb-ai-temporary-inline-action--icon" id="geweb-ai-reset-temp-model" aria-label="<?php echo esc_attr__('Use default model', 'avpvh-ai-search'); ?>" title="<?php echo esc_attr__('Use default model', 'avpvh-ai-search'); ?>">
                                            <span class="geweb-ai-temporary-inline-action-icon" aria-hidden="true">↺</span>
                                        </button>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div class="geweb-ai-temporary-settings-row">
                                <label for="geweb-ai-temporary-prompt" class="geweb-ai-model-selector-label"><?php echo esc_html__('Prompt', 'avpvh-ai-search'); ?></label>
                                <div class="geweb-ai-temporary-settings-controls geweb-ai-temporary-settings-controls--prompt">
                                    <div id="geweb-ai-temporary-prompt-summary" class="geweb-ai-temporary-prompt-summary"><?php echo esc_html($currentPromptName); ?></div>
                                    <button type="button" class="geweb-ai-icon-button geweb-ai-secondary-button geweb-ai-temporary-inline-action geweb-ai-temporary-inline-action--icon" id="geweb-ai-toggle-prompt-editor" aria-label="<?php echo esc_attr__('Edit prompt', 'avpvh-ai-search'); ?>" title="<?php echo esc_attr__('Edit prompt', 'avpvh-ai-search'); ?>">
                                        <span class="geweb-ai-temporary-inline-action-icon geweb-ai-temporary-inline-action-icon--edit" aria-hidden="true">✎</span>
                                    </button>
                                    <button type="button" class="geweb-ai-icon-button geweb-ai-secondary-button geweb-ai-temporary-reset-button geweb-ai-temporary-inline-action geweb-ai-temporary-inline-action--icon" id="geweb-ai-reset-temp-prompt" aria-label="<?php echo esc_attr__('Use default prompt', 'avpvh-ai-search'); ?>" title="<?php echo esc_attr__('Use default prompt', 'avpvh-ai-search'); ?>">
                                        <span class="geweb-ai-temporary-inline-action-icon" aria-hidden="true">↺</span>
                                    </button>
                                </div>
                            </div>
                            <div class="geweb-ai-temporary-prompt-editor" id="geweb-ai-temporary-prompt-editor" hidden>
                                <textarea id="geweb-ai-temporary-prompt" class="geweb-ai-temporary-prompt" placeholder="<?php echo esc_attr__('Optional prompt override for this question only...', 'avpvh-ai-search'); ?>" disabled><?php echo esc_textarea($currentPromptInstruction); ?></textarea>
                            </div>
                        </div>
                        <div class="geweb-ai-question-input-row">
                            <textarea id="geweb-ai-query-display" placeholder="<?php echo esc_attr(apply_filters('geweb_aisearch_ai_textarea_placeholder', 'Ask AI a question...')); ?>"></textarea>
                            <button id="geweb-ask-ai-submit" class="btn" type="submit" disabled aria-label="<?php echo esc_attr__('Send AI question', 'avpvh-ai-search'); ?>"></button>
                        </div>
                    </div>
                </div>
                <div class="geweb-ai-pane-resizer geweb-ai-pane-resizer--right" data-resize-target="right" aria-orientation="vertical" aria-label="<?php echo esc_attr__('Resize sources panel', 'avpvh-ai-search'); ?>"></div>
                <aside class="geweb-ai-sources-panel" data-mobile-pane="right" tabindex="-1" aria-label="<?php echo esc_attr__('Source references panel', 'avpvh-ai-search'); ?>">
                    <div class="geweb-ai-panel-heading">
                        <div class="geweb-ai-panel-heading-main">
                            <div class="geweb-ai-panel-title"><?php echo esc_html__('Source references', 'avpvh-ai-search'); ?></div>
                            <div class="geweb-ai-panel-heading-actions">
                                <?php if (current_user_can('manage_options')): ?>
                                    <a class="geweb-ai-overview-action-button geweb-ai-overview-action-button--icon-first" href="<?php echo esc_url(($this->getTabUrl)('documents')); ?>" aria-label="<?php echo esc_attr__('Manage source references', 'avpvh-ai-search'); ?>" title="<?php echo esc_attr__('Manage source references', 'avpvh-ai-search'); ?>">
                                        <span class="geweb-ai-overview-action-icon" aria-hidden="true">⚙</span>
                                        <span class="geweb-ai-overview-action-label"><?php echo esc_html__('Manage', 'avpvh-ai-search'); ?></span>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button type="button" class="geweb-ai-icon-button geweb-ai-panel-collapse" data-panel-toggle="right" aria-expanded="true" aria-label="<?php echo esc_attr__('Collapse sources panel', 'avpvh-ai-search'); ?>" title="<?php echo esc_attr__('Collapse sources panel', 'avpvh-ai-search'); ?>">
                            <span class="geweb-ai-panel-collapse-icon" aria-hidden="true">▶</span>
                        </button>
                    </div>
                    <p class="geweb-ai-sources-help"><?php echo esc_html__('Pages, posts, and documents referenced by the current answer or restored chat.', 'avpvh-ai-search'); ?></p>
                    <div id="geweb-ai-sources" class="geweb-ai-sources"></div>
                </aside>
            </div>
            <?php if ($isFrontendAiPage): ?>
                <nav class="geweb-ai-mobile-pane-footer" aria-label="<?php echo esc_attr__('Workspace panes', 'avpvh-ai-search'); ?>">
                    <button type="button" class="geweb-ai-icon-button geweb-ai-mobile-pane-tab" data-mobile-pane-target="left" aria-pressed="false">
                        <span class="geweb-ai-mobile-pane-tab-icon" aria-hidden="true">☰</span>
                        <span class="geweb-ai-mobile-pane-tab-label"><?php echo esc_html__('History', 'avpvh-ai-search'); ?></span>
                    </button>
                    <button type="button" class="geweb-ai-icon-button geweb-ai-mobile-pane-tab" data-mobile-pane-target="main" aria-pressed="true">
                        <span class="geweb-ai-mobile-pane-tab-icon" aria-hidden="true">✎</span>
                        <span class="geweb-ai-mobile-pane-tab-label"><?php echo esc_html__('AI chat', 'avpvh-ai-search'); ?></span>
                    </button>
                    <button type="button" class="geweb-ai-icon-button geweb-ai-mobile-pane-tab" data-mobile-pane-target="right" aria-pressed="false">
                        <span class="geweb-ai-mobile-pane-tab-icon" aria-hidden="true">#</span>
                        <span class="geweb-ai-mobile-pane-tab-label"><?php echo esc_html__('Sources', 'avpvh-ai-search'); ?></span>
                    </button>
                </nav>
            <?php endif; ?>
        </<?php echo esc_html($tagName); ?>>
        <?php
    }

    private function renderFrontendAiPageToolbar(): void {
        ?>
        <section class="geweb-ai-page-toolbar" aria-label="<?php echo esc_attr__('AI workspace controls', 'avpvh-ai-search'); ?>">
            <div class="geweb-ai-page-toolbar-title"><?php echo esc_html__('AI Workspace', 'avpvh-ai-search'); ?></div>
            <div class="geweb-ai-page-toolbar-actions">
                <button
                    type="button"
                    class="geweb-ai-icon-button geweb-ai-page-toolbar-button geweb-ai-page-toolbar-button--menu"
                    id="geweb-ai-toggle-mobile-menu"
                    data-panel-toggle="left"
                    aria-label="<?php echo esc_attr__('Show chats panel', 'avpvh-ai-search'); ?>"
                    title="<?php echo esc_attr__('Show chats panel', 'avpvh-ai-search'); ?>"
                    aria-expanded="false"
                >
                    <span class="geweb-ai-page-toolbar-button-icon" aria-hidden="true">☰</span>
                    <span class="geweb-ai-page-toolbar-button-label"><?php echo esc_html__('Chats', 'avpvh-ai-search'); ?></span>
                </button>
                <button
                    type="button"
                    class="geweb-ai-icon-button geweb-ai-page-toolbar-button geweb-ai-page-toolbar-button--align"
                    id="geweb-ai-align-workspace"
                    aria-label="<?php echo esc_attr__('Align workspace to the browser window', 'avpvh-ai-search'); ?>"
                    title="<?php echo esc_attr__('Align workspace to the browser window', 'avpvh-ai-search'); ?>"
                >
                    <span class="geweb-ai-page-toolbar-button-icon" aria-hidden="true">↕</span>
                    <span class="geweb-ai-page-toolbar-button-label"><?php echo esc_html__('Align', 'avpvh-ai-search'); ?></span>
                </button>
                <button
                    type="button"
                    class="geweb-ai-icon-button geweb-ai-page-toolbar-button geweb-ai-page-toolbar-button--fullscreen"
                    id="geweb-ai-toggle-fullscreen"
                    aria-label="<?php echo esc_attr__('Enter fullscreen', 'avpvh-ai-search'); ?>"
                    title="<?php echo esc_attr__('Enter fullscreen', 'avpvh-ai-search'); ?>"
                >
                    <span class="geweb-ai-page-toolbar-button-icon" aria-hidden="true">⛶</span>
                    <span class="geweb-ai-page-toolbar-button-label"><?php echo esc_html__('Fullscreen', 'avpvh-ai-search'); ?></span>
                </button>
                <?php if (current_user_can('manage_options')): ?>
                    <a
                        class="geweb-ai-icon-button geweb-ai-page-toolbar-button geweb-ai-page-toolbar-button--settings"
                        href="<?php echo esc_url(admin_url('admin.php?page=geweb-ai-search')); ?>"
                        aria-label="<?php echo esc_attr__('Open AI search settings', 'avpvh-ai-search'); ?>"
                        title="<?php echo esc_attr__('Open AI search settings', 'avpvh-ai-search'); ?>"
                    >
                        <span class="geweb-ai-page-toolbar-button-icon" aria-hidden="true">⚙</span>
                        <span class="geweb-ai-page-toolbar-button-label"><?php echo esc_html__('Settings', 'avpvh-ai-search'); ?></span>
                    </a>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }

    public function maybeRenderFrontendAiPage(): void {
        if (!FrontendAiContext::isDedicatedFrontendAiPageRequest()) {
            return;
        }

        status_header(200);
        nocache_headers();
        add_filter('body_class', [FrontendAiContext::class, 'filterFrontendAiBodyClasses']);
        get_header();
        remove_filter('body_class', [FrontendAiContext::class, 'filterFrontendAiBodyClasses']);
        ?>
            <main id="geweb-ai-page-root" class="geweb-ai-page-root">
                <?php $this->renderModals(); ?>
            </main>
        <?php
        get_footer();
        exit;
    }

    private function renderFrontendAiSearchResultsPanel(): void {
        $query = FrontendAiContext::getRequestedFrontendQuery();
        $emptyStateHint = __('Search this site here to show regular WordPress results without leaving the AI workspace.', 'avpvh-ai-search');
        $resultsInfoText = $query !== ''
            ? __('These are the regular WordPress search results for the current query.', 'avpvh-ai-search')
            : $emptyStateHint;
        $conversationId = FrontendAiContext::getRequestedFrontendConversationId();
        $initialHeight = isset($this->renderOverrides['search_results_initial_height'])
            ? (int) $this->renderOverrides['search_results_initial_height']
            : ($query !== '' ? 70 : 0);
        $initialHeight = max(0, min(100, $initialHeight));
        $isCollapsed = $initialHeight <= 0;
        ?>
        <section class="geweb-ai-search-results-panel<?php echo $query === '' ? ' geweb-ai-search-results-panel--empty' : ''; ?><?php echo $isCollapsed ? ' is-collapsed' : ''; ?>"<?php echo $query === '' ? ' title="' . esc_attr($emptyStateHint) . '"' : ''; ?>>
            <div class="geweb-ai-search-results-header"<?php echo $query === '' ? ' title="' . esc_attr($emptyStateHint) . '"' : ''; ?>>
                <div class="geweb-ai-panel-heading geweb-ai-panel-heading--search-results">
                    <form class="geweb-ai-search-form geweb-ai-inline-search-form" role="search" action="<?php echo esc_url(FrontendAiContext::getCurrentFrontendAiPageUrl()); ?>" method="get">
                        <label class="screen-reader-text" for="geweb-ai-inline-search"><?php echo esc_html__('Search this site', 'avpvh-ai-search'); ?></label>
                        <input
                            type="search"
                            id="geweb-ai-inline-search"
                            name="s"
                            class="geweb-ai-search-input"
                            value="<?php echo esc_attr($query); ?>"
                            placeholder="<?php echo esc_attr__('Search results', 'avpvh-ai-search'); ?>"
                            autocomplete="off"
                        >
                        <?php if ($conversationId !== ''): ?>
                            <input type="hidden" name="geweb_ai_conversation" value="<?php echo esc_attr($conversationId); ?>">
                        <?php endif; ?>
                        <button type="submit" class="geweb-ai-icon-button geweb-ai-inline-search-submit" aria-label="<?php echo esc_attr__('Search this site', 'avpvh-ai-search'); ?>">
                            <span aria-hidden="true">🔎</span>
                        </button>
                    </form>
                    <button
                        type="button"
                        class="geweb-ai-search-results-info"
                        aria-label="<?php echo esc_attr($resultsInfoText); ?>"
                        title="<?php echo esc_attr($resultsInfoText); ?>"
                    >
                        <span class="geweb-ai-search-results-info-icon" aria-hidden="true">i</span>
                    </button>
                    <button type="button" class="geweb-ai-icon-button geweb-ai-panel-collapse" data-panel-toggle="search" aria-expanded="<?php echo !$isCollapsed ? 'true' : 'false'; ?>" aria-label="<?php echo esc_attr(!$isCollapsed ? __('Collapse search results', 'avpvh-ai-search') : __('Expand search results', 'avpvh-ai-search')); ?>" title="<?php echo esc_attr(!$isCollapsed ? __('Collapse search results', 'avpvh-ai-search') : __('Expand search results', 'avpvh-ai-search')); ?>">
                        <span class="geweb-ai-panel-collapse-icon" aria-hidden="true"><?php echo !$isCollapsed ? '▴' : '🔎'; ?></span>
                    </button>
                </div>
            </div>
            <div class="geweb-ai-search-results-content">
                <?php if ($query !== ''): ?>
                    <?php
                    $postTypes = get_option('geweb_aisearch_post_types', ['post']);
                    if (!is_array($postTypes) || empty($postTypes)) {
                        $postTypes = ['post'];
                    }
                    $searchQuery = new \WP_Query([
                        'post_type' => $postTypes,
                        'post_status' => 'publish',
                        's' => $query,
                        'posts_per_page' => 8,
                        'post__not_in' => array_filter([FrontendAiContext::getFrontendAiPageId()]),
                    ]);
                    ?>
                    <?php if ($searchQuery->have_posts()): ?>
                        <ul class="geweb-ai-search-results-list">
                            <?php while ($searchQuery->have_posts()): $searchQuery->the_post(); ?>
                                <li class="geweb-ai-search-result-item">
                                    <a href="<?php the_permalink(); ?>" class="geweb-ai-search-result-link"><?php the_title(); ?></a>
                                    <div class="geweb-ai-search-result-excerpt"><?php echo esc_html(FrontendAiContext::buildFrontendSearchResultExcerpt(get_the_ID())); ?></div>
                                </li>
                            <?php endwhile; ?>
                        </ul>
                    <?php else: ?>
                        <p class="geweb-ai-empty-panel"><?php echo esc_html__('No normal WordPress search results were found for this query.', 'avpvh-ai-search'); ?></p>
                    <?php endif; ?>
                    <?php wp_reset_postdata(); ?>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }

    private function shouldLoadFullFrontendWorkspace(): bool {
        if (is_admin()) {
            return false;
        }

        if (FrontendAiContext::getFrontendAiInterface() !== 'fullscreen') {
            return true;
        }

        if (FrontendAiContext::isFrontendAiPageRequest($this->shortcodePageViewActive)) {
            return true;
        }

        return $this->hasCurrentPageShortcode();
    }

    /**
     * @return array<string,mixed>
     */
    private function buildFrontendLauncherConfig(string $searchWithAiLabel): array {
        return [
            'frontend_ai_interface' => FrontendAiContext::getFrontendAiInterface(),
            'frontend_ai_page_url' => FrontendAiContext::getFrontendAiPageUrl(),
            'frontend_ai_exit_url' => FrontendAiContext::getFrontendAiExitUrl(),
            'is_frontend_ai_page' => FrontendAiContext::isFrontendAiPageRequest($this->shortcodePageViewActive),
            'i18n' => [
                'openAiSearch' => __('Open AI Search', 'avpvh-ai-search'),
                'searchWithAi' => $searchWithAiLabel,
            ],
        ];
    }

    private function getFrontendAiRequestTimeoutSeconds(string $selectedModel): int {
        $resolvedModel = strtolower(trim($selectedModel));
        $isPro = GeminiModelRules::usesProTimeout($resolvedModel);
        $configuredTimeout = $isPro
            ? (int) get_option(Gemini::OPTION_TIMEOUT_PRO, Gemini::DEFAULT_PRO_HTTP_TIMEOUT_SECONDS)
            : (int) get_option(Gemini::OPTION_TIMEOUT_FLASH, Gemini::DEFAULT_HTTP_TIMEOUT_SECONDS);
        $baseTimeout = $isPro ? Gemini::DEFAULT_PRO_HTTP_TIMEOUT_SECONDS : Gemini::DEFAULT_HTTP_TIMEOUT_SECONDS;
        $effectiveTimeout = max(1, $configuredTimeout > 0 ? $configuredTimeout : $baseTimeout);

        // Keep the browser wait longer than the backend timeout so streaming/polling has room to breathe.
        return $effectiveTimeout + 60;
    }

    private function hasCurrentPageShortcode(): bool {
        if (!is_singular()) {
            return false;
        }

        $post = get_post();
        if (!$post instanceof \WP_Post) {
            return false;
        }

        return self::hasSearchShortcode((string) $post->post_content);
    }

    /**
     * Whether content holds the search shortcode under its current or its old name.
     */
    public static function hasSearchShortcode(string $content): bool {
        return has_shortcode($content, 'avpvh_ai_search') || has_shortcode($content, 'geweb_ai_search');
    }
}
