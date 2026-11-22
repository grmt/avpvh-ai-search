<?php
declare(strict_types=1);

use Geweb\AISearch\AdminSetupChecklist;
use PHPUnit\Framework\TestCase;

final class AdminSetupChecklistTest extends TestCase {
    public function testIncompleteChecklistRendersDirectAnchorLinks(): void {
        $items = AdminSetupChecklist::buildFromContext([
            'activeWorkspaceId' => 'default',
            'connectionStatus' => ['status' => 'ok'],
            'hasValidSavedApiKey' => true,
            'storeEnabled' => true,
            'shareGeminiConfigWithFrontend' => false,
            'postTypes' => [],
            'includeReferencedDocuments' => false,
        ]);

        self::assertCount(3, AdminSetupChecklist::getIncompleteItems($items));
        self::assertCount(2, AdminSetupChecklist::getIncompleteItems($items, true));

        ob_start();
        AdminSetupChecklist::renderSettingsBanner($items);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('2 of 5 configured', $html);
        self::assertStringContainsString('#geweb-setting-frontend-sharing', $html);
        self::assertStringContainsString('#geweb-setting-post-types', $html);
        self::assertStringContainsString('#geweb-setting-referenced-documents', $html);
        self::assertStringContainsString('geweb_workspace_id=default', $html);
    }

    public function testCompleteChecklistRendersNoBanner(): void {
        $items = AdminSetupChecklist::buildFromContext([
            'activeWorkspaceId' => 'default',
            'connectionStatus' => ['status' => 'ok'],
            'hasValidSavedApiKey' => true,
            'storeEnabled' => true,
            'shareGeminiConfigWithFrontend' => true,
            'postTypes' => ['page', 'post'],
            'includeReferencedDocuments' => true,
        ]);

        self::assertSame([], AdminSetupChecklist::getIncompleteItems($items));

        ob_start();
        AdminSetupChecklist::renderSettingsBanner($items);
        self::assertSame('', (string) ob_get_clean());
    }

    public function testMissingApiKeyLinksStoreItemToApiKeyAnchor(): void {
        $items = AdminSetupChecklist::buildFromContext([
            'activeWorkspaceId' => '',
            'connectionStatus' => ['status' => 'failed'],
            'hasValidSavedApiKey' => false,
            'storeEnabled' => false,
            'shareGeminiConfigWithFrontend' => false,
            'postTypes' => [],
            'includeReferencedDocuments' => false,
        ]);

        self::assertCount(4, AdminSetupChecklist::getIncompleteItems($items, true));
        self::assertStringContainsString('failed validation', $items[0]['summary']);
        self::assertStringContainsString('#geweb-setting-api-key', $items[1]['url']);
    }
}
