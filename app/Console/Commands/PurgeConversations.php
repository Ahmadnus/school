<?php

namespace App\Console\Commands;

use App\Enums\AttachmentOwner;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * يحذف محادثات — الكاسرة منها أو كلّها.
 *
 * العطل الذي مضى كان يحفظ الخيط بمشاركٍ واحد هو منشئه: الموظّف يكتب عن طالب
 * فلا يجد الخادم لوليّ أمره حساباً، فيُحفظ الخيط ولا يصل إلى أحد. تلك الخيوط
 * لا يُصلِحها النشر وحده — هي محفوظة ناقصةً، فتبقى في القائمة تُوهم بأنّ
 * مراسلةً جرت ولم تجرِ.
 *
 * والحذف هنا يتولّى ما لا تتولّاه قاعدة البيانات: الرسائل والمشاركون يسقطون
 * بالتتابع (`cascadeOnDelete`)، أمّا **المرفقات فجدولها polymorphic بلا مفتاح
 * أجنبي** فلا يسقط شيء منها. وكذلك الإشعارات: `ref_id` يشير إلى خيطٍ ذهب،
 * فيفتح المستخدم الإشعار على فراغ.
 *
 * ملفّات المرفقات على القرص **لا تُحذف من هنا**: تُكتَب مساراتها في ملفٍّ
 * ليراجعها صاحب النظام ويحذفها بنفسه. حذف الملفّات لا يُستردّ، ومسارٌ خاطئ
 * في استعلامٍ واحد يمسح مرفقات لا علاقة لها بالأمر.
 */
class PurgeConversations extends Command
{
    protected $signature = 'conversations:purge
        {--broken : الخيوط التي لها مشاركٌ واحد أو لا مشارك — ما أسقطه العطل}
        {--all : كل المحادثات — بداية من الصفر}
        {--school= : حصر الحذف بمدرسة واحدة}
        {--dry-run : يَعُدّ ولا يحذف}
        {--force : بلا سؤال تأكيد}';

    protected $description = 'حذف المحادثات الكاسرة (أو كلّها) مع صفوف مرفقاتها وإشعاراتها';

    public function handle(): int
    {
        if (! $this->option('broken') && ! $this->option('all')) {
            $this->error('اختر نطاقاً: --broken أو --all.');

            return self::FAILURE;
        }

        if ($this->option('broken') && $this->option('all')) {
            $this->error('--broken و--all لا يجتمعان.');

            return self::FAILURE;
        }

        $ids = Conversation::query()
            ->when($this->option('school'), fn ($q, $id) => $q->where('school_id', (int) $id))
            // مشاركٌ واحد يعني خيطاً لا يراه غير منشئه.
            ->when($this->option('broken'), fn ($q) => $q->has('participantRecords', '<', 2))
            ->pluck('id');

        if ($ids->isEmpty()) {
            $this->info('لا محادثة تطابق النطاق. لا شيء ليُحذف.');

            return self::SUCCESS;
        }

        $messageIds = Message::query()->whereIn('conversation_id', $ids)->pluck('id');

        $attachments = Attachment::query()
            ->where('owner_type', AttachmentOwner::Message)
            ->whereIn('owner_id', $messageIds)
            ->get(['id', 'path']);

        $this->table(['ما سيُحذف', 'العدد'], [
            ['محادثات', $ids->count()],
            ['رسائل', $messageIds->count()],
            ['صفوف مرفقات', $attachments->count()],
        ]);

        if ($this->option('dry-run')) {
            $this->warn('تجربة فقط (--dry-run): لم يُحذف شيء.');

            return self::SUCCESS;
        }

        // الحذف لا يُستردّ، فلا يجري بلا إقرارٍ صريح.
        if (! $this->option('force') && ! $this->confirm('الحذف نهائيّ ولا رجعة فيه. أُتابع؟', false)) {
            $this->info('أُلغي.');

            return self::SUCCESS;
        }

        // المسارات تُكتَب **قبل** حذف الصفوف: بعده لا يبقى ما يدلّ عليها.
        $manifest = null;

        if ($attachments->isNotEmpty()) {
            $manifest = storage_path('app/purged-attachments-'.now()->format('Ymd-His').'.txt');
            file_put_contents(
                $manifest,
                $attachments->pluck('path')->implode(PHP_EOL).PHP_EOL,
            );
        }

        DB::transaction(function () use ($ids, $attachments) {
            Attachment::query()->whereIn('id', $attachments->pluck('id'))->delete();

            // إشعارٌ يشير إلى خيطٍ ذهب يفتح على فراغ.
            Notification::query()
                ->where('type', 'message_received')
                ->whereIn('ref_id', $ids)
                ->delete();

            // الرسائل والمشاركون يسقطون بالتتابع مع الخيط.
            Conversation::query()->whereIn('id', $ids)->delete();
        });

        $this->info("حُذفت {$ids->count()} محادثة.");

        if ($manifest !== null) {
            $this->warn('ملفّات المرفقات بقيت على القرص. مساراتها في:');
            $this->line($manifest);
            $this->line('راجعها ثمّ احذفها بنفسك — الحذف لا يُستردّ.');
        }

        return self::SUCCESS;
    }
}
