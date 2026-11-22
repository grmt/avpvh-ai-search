<?php
declare(strict_types=1);

use Geweb\AISearch\SimpleFileListNiceNameUpdater;
use Geweb\AISearch\SimpleFileListSupport;
use PHPUnit\Framework\TestCase;

final class SimpleFileListSupportTest extends TestCase {
    private string $avpvhDir1;
    private string $avpvhDir2;
    private string $legacyDir;

    protected function setUp(): void {
        $uploadsDir = $GLOBALS['avpvh_test_uploads_dir'];
        $this->avpvhDir1 = $uploadsDir . '/avpvh-filelist';
        $this->avpvhDir2 = $uploadsDir . '/list-two';
        $this->legacyDir = $uploadsDir . '/simple-file-list';

        foreach ([$this->avpvhDir1, $this->avpvhDir2, $this->legacyDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
        }

        file_put_contents($this->avpvhDir1 . '/13_ams_25_104_F_V_01.pdf', '%PDF-1.4 list 1');
        file_put_contents($this->avpvhDir1 . '/report.pdf', '%PDF-1.4 list 1 duplicate');
        file_put_contents($this->avpvhDir2 . '/report.pdf', '%PDF-1.4 list 2');
        file_put_contents($this->legacyDir . '/legacy_only.pdf', '%PDF-1.4 legacy');

        $GLOBALS['avpvh_test_options'] = [];
        $GLOBALS['avpvh_test_shortcodes'] = [];
    }

    public function testLegacySimpleFileListFallback(): void {
        $GLOBALS['avpvh_test_shortcodes'] = ['eeSFL' => true];
        $GLOBALS['avpvh_test_options'] = [
            'eeSFL_Settings_1' => ['FileListDir' => 'wp-content/uploads/simple-file-list/'],
            'eeSFL_FileList_1' => [
                ['FilePath' => 'legacy_only.pdf', 'FileNiceName' => 'Legacy Doc', 'FileExt' => 'pdf'],
            ],
        ];

        $support = new SimpleFileListSupport();
        self::assertFalse($support->isUsingAvpvhFileList());
        self::assertTrue($support->isSimpleFileListPage(new WP_Post('[eeSFL]')));

        $entries = $support->getSimpleFileListEntries();
        self::assertCount(1, $entries);
        self::assertSame('Legacy Doc', $entries[0]['display_name']);
        self::assertStringContainsString('page=ee-simple-file-list', $support->buildSimpleFileListAdminUrl($entries[0]['file_path']));
    }

    public function testAvpvhFileListPrefersMigratedOptionsAndResolvesPerListDir(): void {
        $GLOBALS['avpvh_test_shortcodes'] = ['avpvh_filelist' => true, 'eeSFL' => true];
        $GLOBALS['avpvh_test_options'] = [
            'eeSFL_Settings_1' => ['FileListDir' => 'wp-content/uploads/simple-file-list/'],
            'eeSFL_FileList_1' => [
                ['FilePath' => 'legacy_only.pdf', 'FileNiceName' => 'Stale Legacy', 'FileExt' => 'pdf'],
            ],
            'avpvh_flst_settings_1' => ['FileListDir' => 'wp-content/uploads/avpvh-filelist/'],
            'avpvh_flst_files_1' => [
                ['FilePath' => 'subdir', 'FileNiceName' => '', 'FileExt' => 'folder'],
                ['FilePath' => '13_ams_25_104_F_V_01.pdf', 'FileNiceName' => 'Verslag curator 1', 'FileExt' => 'pdf'],
            ],
            'avpvh_flst_settings_2' => ['FileListDir' => 'wp-content/uploads/list-two/'],
            'avpvh_flst_files_2' => [
                ['FilePath' => 'report.pdf', 'FileNiceName' => 'List Two Report', 'FileExt' => 'pdf'],
            ],
        ];

        $support = new SimpleFileListSupport();
        self::assertTrue($support->isUsingAvpvhFileList());
        self::assertTrue($support->isSimpleFileListPage(new WP_Post('[avpvh_filelist list="2"]')));

        $entries = $support->getSimpleFileListEntries();
        self::assertCount(2, $entries);

        $byPath = [];
        foreach ($entries as $entry) {
            $byPath[$entry['file_path']] = $entry;
        }

        $expectedPath1 = wp_normalize_path($this->avpvhDir1 . '/13_ams_25_104_F_V_01.pdf');
        $expectedPath2 = wp_normalize_path($this->avpvhDir2 . '/report.pdf');
        self::assertArrayHasKey($expectedPath1, $byPath);
        self::assertArrayHasKey($expectedPath2, $byPath);
        self::assertSame(2, $byPath[$expectedPath2]['list_id']);

        $adminUrl = $support->buildSimpleFileListAdminUrl($expectedPath2, 2);
        self::assertStringContainsString('page=avpvh-filelist', $adminUrl);
        self::assertStringContainsString('eeListID=2', $adminUrl);
        self::assertStringContainsString('sfl_search_2=report.pdf', $adminUrl);

        $updater = new SimpleFileListNiceNameUpdater($support);
        $matchedOption = null;
        foreach ($support->getFileListOptionRows() as $row) {
            $updated = $updater->buildUpdatedOption($row, $expectedPath2, 'Renamed Report');
            if ($updated !== null) {
                $matchedOption = [$row['option_name'], $updated];
            }
        }
        self::assertNotNull($matchedOption);
        self::assertSame('avpvh_flst_files_2', $matchedOption[0]);
        self::assertSame('Renamed Report', $matchedOption[1][0]['FileNiceName']);

        self::assertTrue($support->removeSimpleFileListEntryByPath($expectedPath2));
        self::assertCount(0, $GLOBALS['avpvh_test_options']['avpvh_flst_files_2']);
        self::assertCount(2, $GLOBALS['avpvh_test_options']['avpvh_flst_files_1']);
    }
}
