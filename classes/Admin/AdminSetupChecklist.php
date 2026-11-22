<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Evaluates AV-PvH AI Search configuration readiness and renders actionable checklist links.
 */
class AdminSetupChecklist {
    private const ADMIN_PAGE_SLUG = 'geweb-ai-search';

    public static function registerHooks(): void {
        add_action('admin_notices', [self::class, 'renderGlobalAdminNotice']);
    }

    /**
     * Build setup checklist items from an already-assembled admin context.
     *
     * @param array<string,mixed> $context
     * @return array<int,array<string,mixed>>
     */
    public static function buildFromContext(array $context): array {
        $workspaceId = isset($context['activeWorkspaceId']) ? (string) $context['activeWorkspaceId'] : '';
        $connectionStatus = is_array($context['connectionStatus'] ?? null) ? $context['connectionStatus'] : [];
        $hasValidApiKey = !empty($context['hasValidSavedApiKey']);
        $apiState = (string) ($connectionStatus['status'] ?? '');
        $storeEnabled = !empty($context['storeEnabled']);
        $shareWithFrontend = !empty($context['shareGeminiConfigWithFrontend']);
        $postTypes = is_array($context['postTypes'] ?? null) ? array_filter($context['postTypes']) : [];
        $includeReferenced = !empty($context['includeReferencedDocuments']);

        return [
            self::buildApiKeyItem($hasValidApiKey, $apiState, $workspaceId),
            self::buildStoreItem($storeEnabled, $hasValidApiKey, $workspaceId),
            self::buildFrontendSharingItem($shareWithFrontend, $workspaceId),
            self::buildPostTypesItem(!empty($postTypes), $workspaceId),
            self::buildReferencedDocumentsItem($includeReferenced, $workspaceId),
        ];
    }

    /**
     * Build setup checklist items directly from current WordPress options.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function buildCurrent(): array {
        try {
            $provider = ProviderFactory::make();
            $connectionStatus = $provider->getConnectionStatus();
            $storeEnabled = !empty($provider->getStoreData());
        } catch (\Throwable $e) {
            $connectionStatus = [];
            $storeEnabled = false;
        }

        $hasValidApiKey = is_array($connectionStatus) && (($connectionStatus['status'] ?? '') === 'ok');

        return self::buildFromContext([
            'activeWorkspaceId' => UserScope::getCurrentWorkspaceId(),
            'connectionStatus' => $connectionStatus,
            'hasValidSavedApiKey' => $hasValidApiKey,
            'storeEnabled' => $storeEnabled,
            'shareGeminiConfigWithFrontend' => FrontendAiAccess::isSharedGeminiConfigEnabled(),
            'postTypes' => get_option('geweb_aisearch_post_types', []),
            'includeReferencedDocuments' => get_option('geweb_aisearch_include_referenced_documents', '0') === '1',
        ]);
    }

    /**
     * Filter checklist items down to incomplete entries, optionally restricted to required items.
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    public static function getIncompleteItems(array $items, bool $requiredOnly = false): array {
        return array_values(array_filter($items, static function (array $item) use ($requiredOnly): bool {
            if (!empty($item['done'])) {
                return false;
            }

            return !$requiredOnly || (($item['severity'] ?? 'required') === 'required');
        }));
    }

    /**
     * Render the setup checklist banner on the plugin settings page when items are still incomplete.
     *
     * @param array<int,array<string,mixed>> $items
     */
    public static function renderSettingsBanner(array $items): void {
        $incomplete = self::getIncompleteItems($items);
        if (empty($incomplete)) {
            return;
        }

        $doneCount = count($items) - count($incomplete);
        $totalCount = count($items);
        $hasRequiredMissing = !empty(self::getIncompleteItems($items, true));
        $noticeClass = $hasRequiredMissing ? 'notice-warning' : 'notice-info';

        echo '<div class="notice ' . esc_attr($noticeClass) . ' inline" style="margin:0 0 16px; padding:12px 16px; max-width:960px;">';
        echo '<p style="margin:0 0 8px;"><strong>' . esc_html__('Setup checklist', 'avpvh-ai-search') . '</strong> — ';
        echo esc_html(sprintf(
            /* translators: 1: number of completed items, 2: total number of checklist items */
            __('%1$d of %2$d configured. Still needed before everything works smoothly:', 'avpvh-ai-search'),
            $doneCount,
            $totalCount
        ));
        echo '</p>';
        echo '<ul style="margin:0; padding-left:20px; list-style:disc;">';
        foreach ($incomplete as $item) {
            self::renderChecklistListItem($item);
        }
        echo '</ul>';
        echo '</div>';
    }

    /**
     * Render a compact WP admin notice on other admin screens when required configuration is missing.
     */
    public static function renderGlobalAdminNotice(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if ($screen && strpos((string) $screen->id, self::ADMIN_PAGE_SLUG) !== false) {
                return;
            }
        }

        $missingRequired = self::getIncompleteItems(self::buildCurrent(), true);
        if (empty($missingRequired)) {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>AV-PvH AI Search:</strong> ';
        echo esc_html__('Configuration is incomplete. Still needed:', 'avpvh-ai-search') . ' ';
        foreach ($missingRequired as $index => $item) {
            if ($index > 0) {
                echo ' &middot; ';
            }
            echo '<a href="' . esc_url((string) ($item['url'] ?? '')) . '">' . esc_html((string) ($item['label'] ?? '')) . '</a>';
        }
        echo '</p></div>';
    }

    /**
     * @param array<string,mixed> $item
     */
    private static function renderChecklistListItem(array $item): void {
        $label = (string) ($item['label'] ?? '');
        $summary = (string) ($item['summary'] ?? '');
        $actionLabel = (string) ($item['action_label'] ?? '');
        $url = (string) ($item['url'] ?? '');
        $isOptional = (($item['severity'] ?? 'required') !== 'required');

        echo '<li style="margin:4px 0;">';
        echo '<strong>' . esc_html($label) . '</strong>';
        if ($isOptional) {
            echo ' <span style="color:#646970;">(' . esc_html__('recommended', 'avpvh-ai-search') . ')</span>';
        }
        if ($summary !== '') {
            echo ': ' . esc_html($summary);
        }
        if ($url !== '' && $actionLabel !== '') {
            echo ' — <a href="' . esc_url($url) . '">' . esc_html($actionLabel) . '</a>';
        }
        echo '</li>';
    }

    private static function buildSettingsUrl(string $tab, string $workspaceId = '', string $anchor = ''): string {
        $args = [
            'page' => self::ADMIN_PAGE_SLUG,
            'geweb_tab' => $tab,
        ];
        if ($workspaceId !== '') {
            $args['geweb_workspace_id'] = $workspaceId;
        }

        $url = add_query_arg($args, admin_url('admin.php'));
        return $anchor !== '' ? $url . '#' . ltrim($anchor, '#') : $url;
    }

    /**
     * @return array<string,mixed>
     */
    private static function buildApiKeyItem(bool $hasValidApiKey, string $apiState, string $workspaceId): array {
        $summary = $apiState === 'failed'
            ? __('The saved Gemini API key failed validation. Update it and save settings.', 'avpvh-ai-search')
            : __('Enter a Google AI Studio Gemini API key and click Save Settings.', 'avpvh-ai-search');

        return [
            'id' => 'api_key',
            'label' => __('Gemini API key', 'avpvh-ai-search'),
            'done' => $hasValidApiKey,
            'severity' => 'required',
            'summary' => $summary,
            'action_label' => __('Go to API Key', 'avpvh-ai-search'),
            'url' => self::buildSettingsUrl('general', $workspaceId, 'geweb-setting-api-key'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function buildStoreItem(bool $storeEnabled, bool $hasValidApiKey, string $workspaceId): array {
        return [
            'id' => 'gemini_store',
            'label' => __('Gemini File Search store', 'avpvh-ai-search'),
            'done' => $storeEnabled,
            'severity' => 'required',
            'summary' => $hasValidApiKey
                ? __('No Gemini store is attached to this workspace yet. Save Settings or select/create a store.', 'avpvh-ai-search')
                : __('Created automatically after you save a valid Gemini API key.', 'avpvh-ai-search'),
            'action_label' => $hasValidApiKey ? __('Open Gemini Stores', 'avpvh-ai-search') : __('Go to API Key', 'avpvh-ai-search'),
            'url' => $hasValidApiKey
                ? self::buildSettingsUrl('stores', $workspaceId)
                : self::buildSettingsUrl('general', $workspaceId, 'geweb-setting-api-key'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function buildFrontendSharingItem(bool $shareWithFrontend, string $workspaceId): array {
        return [
            'id' => 'frontend_sharing',
            'label' => __('Frontend user access', 'avpvh-ai-search'),
            'done' => $shareWithFrontend,
            'severity' => 'required',
            'summary' => __('Non-admin visitors currently receive HTTP 403 until Gemini search configuration is shared with frontend users.', 'avpvh-ai-search'),
            'action_label' => __('Enable frontend sharing', 'avpvh-ai-search'),
            'url' => self::buildSettingsUrl('general', $workspaceId, 'geweb-setting-frontend-sharing'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function buildPostTypesItem(bool $hasPostTypes, string $workspaceId): array {
        return [
            'id' => 'post_types',
            'label' => __('Post types to index', 'avpvh-ai-search'),
            'done' => $hasPostTypes,
            'severity' => 'required',
            'summary' => __('Select at least one post type (e.g. Pages or Posts) to index site content and show Generate Library.', 'avpvh-ai-search'),
            'action_label' => __('Select post types', 'avpvh-ai-search'),
            'url' => self::buildSettingsUrl('general', $workspaceId, 'geweb-setting-post-types'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function buildReferencedDocumentsItem(bool $includeReferenced, string $workspaceId): array {
        return [
            'id' => 'referenced_documents',
            'label' => __('Referenced / File List document uploads', 'avpvh-ai-search'),
            'done' => $includeReferenced,
            'severity' => 'recommended',
            'summary' => __('Linked uploads and File List files are detected, but will not upload to Gemini until enabled.', 'avpvh-ai-search'),
            'action_label' => __('Enable document uploads', 'avpvh-ai-search'),
            'url' => self::buildSettingsUrl('general', $workspaceId, 'geweb-setting-referenced-documents'),
        ];
    }
}
