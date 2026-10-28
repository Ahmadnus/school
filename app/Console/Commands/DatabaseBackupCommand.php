<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * نسخة احتياطية مضغوطة لقاعدة البيانات، مع تدوير النسخ القديمة.
 *
 * ```
 * php artisan db:backup            # نسخة الآن
 * php artisan db:backup --keep=30  # أبقِ ٣٠ يوماً
 * ```
 *
 * يعمل يومياً من المُجدوِل (`routes/console.php`). الملفات في
 * `storage/app/backups/` بصلاحيات مغلقة. **النسخة لا تُعدّ سليمة قبل أن
 * يُتحقَّق منها**: يُقرأ ختام الملف (`Dump completed`) فإن غاب عُدّت فاشلة
 * ولم تُحتسب. لا تحذف الدورة أبداً آخر ٣ نسخ مهما كان عمرها.
 *
 * الاستعادة: `gunzip -c backup.sql.gz | mysql -u USER -p DATABASE` (انظر
 * `docs/BACKUP.md`).
 */
class DatabaseBackupCommand extends Command
{
    protected $signature = 'db:backup {--keep=14 : عدد الأيام التي تُحفظ فيها النسخ}';

    protected $description = 'نسخة احتياطية مضغوطة لقاعدة البيانات مع تدوير القديم';

    private const KEEP_AT_LEAST = 3;

    public function handle(): int
    {
        $db = config('database.connections.'.config('database.default'));
        if (($db['driver'] ?? null) !== 'mysql') {
            return $this->fail('النسخ الاحتياطي مدعوم لـ MySQL فقط.');
        }

        $dir = storage_path('app/backups');
        if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) {
            return $this->fail('تعذّر إنشاء مجلد النسخ.');
        }

        $stamp = now()->format('Ymd-His');
        $sql = "{$dir}/backup-{$stamp}.sql";
        $gz = "{$sql}.gz";

        $result = Process::timeout(1800)
            ->env(['MYSQL_PWD' => (string) ($db['password'] ?? '')])
            ->run([
                'mysqldump',
                '--single-transaction', '--quick', '--routines', '--no-tablespaces',
                '--default-character-set=utf8mb4',
                '-h', (string) $db['host'], '-P', (string) $db['port'], '-u', (string) $db['username'],
                '--result-file='.$sql,
                (string) $db['database'],
            ]);

        if ($result->failed()) {
            @unlink($sql);

            return $this->fail('فشل mysqldump: '.trim($result->errorOutput()));
        }

        if (! $this->isComplete($sql)) {
            @unlink($sql);

            return $this->fail('النسخة غير مكتملة — لم تُحفظ.');
        }

        $this->compress($sql, $gz);
        @unlink($sql);
        @chmod($gz, 0640);

        $this->info(sprintf('تمت النسخة: %s (%s)', basename($gz), $this->size((int) filesize($gz))));
        $this->prune($dir, (int) $this->option('keep'));

        return self::SUCCESS;
    }

    /** mysqldump يختم الملف بسطر `Dump completed` حين ينتهي سليماً. */
    private function isComplete(string $file): bool
    {
        if (! is_file($file) || filesize($file) === 0) {
            return false;
        }
        $handle = fopen($file, 'rb');
        fseek($handle, -min(512, (int) filesize($file)), SEEK_END);
        $tail = (string) fread($handle, 512);
        fclose($handle);

        return str_contains($tail, 'Dump completed');
    }

    private function compress(string $from, string $to): void
    {
        $in = fopen($from, 'rb');
        $out = gzopen($to, 'wb9');
        while (! feof($in)) {
            gzwrite($out, (string) fread($in, 1 << 20));
        }
        gzclose($out);
        fclose($in);
    }

    /** يحذف ما تجاوز المدة، ويُبقي دائماً أحدث [KEEP_AT_LEAST] نسخ. */
    private function prune(string $dir, int $keepDays): void
    {
        $files = glob($dir.'/backup-*.sql.gz') ?: [];
        rsort($files); // الاسم يحمل الطابع الزمني: الأحدث أولاً.
        $cutoff = now()->subDays(max($keepDays, 1))->getTimestamp();

        foreach (array_slice($files, self::KEEP_AT_LEAST) as $old) {
            if (filemtime($old) < $cutoff) {
                @unlink($old);
                $this->line('حُذفت نسخة قديمة: '.basename($old));
            }
        }
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : round($bytes / 1024, 1).' KB';
    }
}
