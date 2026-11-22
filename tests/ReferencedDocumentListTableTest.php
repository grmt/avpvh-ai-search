<?php
declare(strict_types=1);

use Geweb\AISearch\ReferencedDocumentListTable;
use PHPUnit\Framework\TestCase;

final class ReferencedDocumentListTableTest extends TestCase {
    private string $samplePdf;

    protected function setUp(): void {
        $dir = $GLOBALS['avpvh_test_uploads_dir'] . '/avpvh-filelist';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $this->samplePdf = wp_normalize_path($dir . '/sample.pdf');
        file_put_contents($this->samplePdf, '%PDF-1.4 sample');
        $GLOBALS['avpvh_test_options'] = [];
        $GLOBALS['avpvh_test_shortcodes'] = ['avpvh_filelist' => true];
    }

    public function testPostAttachmentOutsideFileListIsMarkedNeutrallyNotAsError(): void {
        $table = new ReferencedDocumentListTable();
        $item = [
            'file_hash' => 'hash1234567890',
            'display_name' => 'sample.pdf',
            'file_url' => 'https://example.test/wp-content/uploads/2026/sample.pdf',
            'file_path' => $this->samplePdf,
            'managed_by_simple_file_list' => false,
            'broken_reference' => false,
            'status' => 'Uploaded',
            'status_color' => '#46b450',
            'include_in_store_target' => true,
            'modified_after_upload' => false,
            'operation_status' => '',
            'operation_error' => '',
            'posts' => [['id' => 1861, 'title' => 'Faillissement GLT']],
            'reference_count' => 1,
            'external_reference_count' => 1,
            'mime_type' => 'application/pdf',
        ];

        $nameHtml = $table->renderDisplayNameCell($item);
        $statusHtml = $table->renderStatusCell($item);
        $actionsHtml = $table->renderActionsCell($item);
        $rowHtml = $table->renderRowHtml($item);

        self::assertStringContainsString('Not in File List', $nameHtml);
        self::assertStringNotContainsString('#d63638', $nameHtml);
        self::assertStringContainsString('Indexed', $statusHtml);
        self::assertStringNotContainsString('Error', $statusHtml);
        self::assertStringNotContainsString('Missing from', $actionsHtml);
        self::assertStringContainsString('data-referenced-document-status="indexed"', $rowHtml);
        self::assertStringContainsString('data-referenced-document-referenced-in="not_in_file_list"', $rowHtml);
    }

    public function testBrokenReferenceStillReportsErrorAndFileListOnlyScopeWorks(): void {
        $table = new ReferencedDocumentListTable();
        $broken = [
            'file_hash' => 'broken123456',
            'display_name' => 'missing.pdf',
            'file_url' => 'https://example.test/wp-content/uploads/missing.pdf',
            'file_path' => '',
            'managed_by_simple_file_list' => false,
            'broken_reference' => true,
            'status' => 'Referenced on site, but file is missing',
            'status_color' => '#d63638',
            'include_in_store_target' => true,
            'posts' => [['id' => 23, 'title' => 'Lamberts']],
            'reference_count' => 1,
            'mime_type' => 'application/pdf',
        ];

        $brokenRow = $table->renderRowHtml($broken);
        $brokenName = $table->renderDisplayNameCell($broken);
        self::assertStringContainsString('data-referenced-document-status="error"', $brokenRow);
        self::assertStringContainsString('Broken link or missing file', $brokenName);
        self::assertStringNotContainsString('Not in File List', $brokenName);

        $sflOnly = [
            'file_hash' => 'sfl123456',
            'display_name' => 'sample.pdf',
            'file_url' => 'https://example.test/wp-content/uploads/avpvh-filelist/sample.pdf',
            'file_path' => $this->samplePdf,
            'managed_by_simple_file_list' => true,
            'simple_file_list_id' => 2,
            'broken_reference' => false,
            'status' => 'Only in File List',
            'status_color' => '#996800',
            'include_in_store_target' => false,
            'posts' => [],
            'reference_count' => 0,
            'mime_type' => 'application/pdf',
        ];

        $sflRow = $table->renderRowHtml($sflOnly);
        $sflName = $table->renderDisplayNameCell($sflOnly);
        self::assertStringContainsString('data-referenced-document-referenced-in="simple_file_list_only"', $sflRow);
        self::assertStringContainsString('eeListID=2', $sflName);
        self::assertStringContainsString('sfl_search_2=sample.pdf', $sflName);
    }
}
