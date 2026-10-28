<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * النسخ الاحتياطي: لا تُحتسب نسخة غير مكتملة، والتدوير لا يمسّ الأحدث.
 */
class DatabaseBackupTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('app/backups');
        File::deleteDirectory($this->dir);
        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql.database', 'school');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** mysqldump مزيّف يكتب الملف المطلوب في `--result-file=`. */
    private function fakeDump(string $content): void
    {
        Process::fake(function ($process) use ($content) {
            foreach ((array) $process->command as $part) {
                if (str_starts_with($part, '--result-file=')) {
                    file_put_contents(substr($part, 14), $content);
                }
            }

            return Process::result('', '', 0);
        });
    }

    public function test_a_complete_dump_is_compressed_and_saved(): void
    {
        $this->fakeDump("-- data\nINSERT INTO t VALUES (1);\n-- Dump completed on 2026-10-23\n");

        $this->artisan('db:backup')->assertSuccessful();

        $files = glob($this->dir.'/backup-*.sql.gz');
        $this->assertCount(1, $files);
        $this->assertStringContainsString('INSERT INTO t', (string) gzdecode((string) file_get_contents($files[0])));
        $this->assertSame([], glob($this->dir.'/*.sql'), 'the raw dump must not be left behind');
    }

    public function test_an_incomplete_dump_is_rejected_and_nothing_is_kept(): void
    {
        $this->fakeDump("-- data\nINSERT INTO t VALUES (1);\n");

        $this->artisan('db:backup')->assertFailed();

        $this->assertSame([], glob($this->dir.'/*') ?: []);
    }

    public function test_pruning_removes_old_backups_but_always_keeps_the_newest_three(): void
    {
        File::ensureDirectoryExists($this->dir);
        foreach (['20260101-000000', '20260102-000000', '20260103-000000', '20260104-000000', '20260105-000000'] as $stamp) {
            $path = "{$this->dir}/backup-{$stamp}.sql.gz";
            file_put_contents($path, 'x');
            touch($path, now()->subDays(100)->getTimestamp());
        }
        $this->fakeDump("-- Dump completed\n");

        $this->artisan('db:backup', ['--keep' => 14])->assertSuccessful();

        // أحدث ٣ نسخ محفوظة (الجديدة + أحدث قديمتين)؛ ما عداها حُذف.
        $names = array_map('basename', glob($this->dir.'/backup-*.sql.gz'));
        $this->assertCount(3, $names);
        $this->assertContains('backup-20260105-000000.sql.gz', $names);
        $this->assertContains('backup-20260104-000000.sql.gz', $names);
        $this->assertNotContains('backup-20260101-000000.sql.gz', $names);
    }
}
