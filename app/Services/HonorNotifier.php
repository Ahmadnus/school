<?php

namespace App\Services;

use App\Enums\NotificationApp;
use App\Enums\Status;
use App\Enums\UserRole;
use App\Models\HonorEntry;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * نشر تكريم على لوحة الشرف.
 *
 * إشعاران مختلفان عمداً:
 *  - أهل الطالب المكرَّم: رسالة تهنئة باسمه («مبروك! أحمد على لوحة الشرف»).
 *  - بقية الأهالي: خبر اللوحة («طلاب متميزون جدد على لوحة الشرف») بلا أسماء
 *    في الإشعار — من يريد يفتح اللوحة. هكذا يصل التحفيز بلا أن يتحوّل الإشعار
 *    إلى إعلان عن ابن جارك في جيب كل أب.
 */
class HonorNotifier
{
    public static function published(HonorEntry $entry): void
    {
        $entry->loadMissing(['student.guardians.user', 'student.currentEnrollment.section.grade', 'subject']);

        $student = $entry->student;

        if (! $student) {
            return;
        }

        $ownGuardianIds = [];

        foreach ($student->guardians as $guardian) {
            if (! $guardian->user) {
                continue;
            }

            $ownGuardianIds[] = $guardian->user->id;

            NotificationGate::notify(
                $guardian->user,
                'honor_board',
                __('notifications.honor_own_title', ['name' => $student->first_name]),
                __('notifications.honor_own_body', [
                    'reason' => $entry->reason,
                    'subject' => $entry->subject?->name ?? __('notifications.honor_general'),
                ]),
                $entry->id,
                NotificationApp::Guardian,
            );
        }

        foreach (self::otherGuardians($entry->school_id, $ownGuardianIds) as $user) {
            NotificationGate::notify(
                $user,
                'honor_board',
                __('notifications.honor_board_title'),
                __('notifications.honor_board_body', ['category' => $entry->category->label()]),
                $entry->id,
                NotificationApp::Guardian,
            );
        }

        foreach (self::administrators($entry->school_id) as $admin) {
            NotificationGate::notify(
                $admin,
                'honor_board',
                __('notifications.honor_staff_title', ['name' => $student->full_name]),
                __('notifications.honor_own_body', [
                    'reason' => $entry->reason,
                    'subject' => $entry->subject?->name ?? __('notifications.honor_general'),
                ]),
                $entry->id,
                NotificationApp::Staff,
            );
        }
    }

    /**
     * نشر دفعة تكريمات معاً — «الأوائل الثلاثة في كل شعبة».
     *
     * الفرق عن [published] ليس تحسين أداء بل منع إغراق: نداءها لكل تكريم على
     * حدة يرسل إلى **كل وليّ أمر في المدرسة** إشعاراً مكرّراً عن كل طالب — ثلاثون
     * شعبة × ثلاثة = تسعون إشعاراً متطابقاً في جيب كل أب، فيُطفئ الإشعارات ويضيع معها
     * ما يهمّه. فهنا: تهنئة باسم الابن لأهله (واحدة لكل مكرَّم)، وخبر اللوحة
     * **مرّة واحدة** لبقية الأهالي وللإدارة.
     *
     * @param  array<int, HonorEntry>  $entries
     */
    public static function publishedBatch(array $entries): void
    {
        $entries = array_values(array_filter(
            $entries,
            fn (HonorEntry $e) => $e->isPublished(),
        ));

        if ($entries === []) {
            return;
        }

        // دفعة واحدة = مدرسة واحدة (الطلاب مُتحقّق من مدرستهم في الطلب).
        $schoolId = $entries[0]->school_id;
        $ownGuardianIds = [];
        $names = [];

        foreach ($entries as $entry) {
            $entry->loadMissing(['student.guardians.user', 'subject']);
            $student = $entry->student;

            if (! $student) {
                continue;
            }

            $names[] = $student->full_name;

            foreach ($student->guardians as $guardian) {
                if (! $guardian->user) {
                    continue;
                }

                $ownGuardianIds[] = $guardian->user->id;

                NotificationGate::notify(
                    $guardian->user,
                    'honor_board',
                    __('notifications.honor_own_title', ['name' => $student->first_name]),
                    __('notifications.honor_own_body', [
                        'reason' => $entry->reason,
                        'subject' => $entry->subject?->name ?? __('notifications.honor_general'),
                    ]),
                    $entry->id,
                    NotificationApp::Guardian,
                );
            }
        }

        $count = count($entries);
        $first = $entries[0];

        foreach (self::otherGuardians($schoolId, array_unique($ownGuardianIds)) as $user) {
            NotificationGate::notify(
                $user,
                'honor_board',
                __('notifications.honor_board_title'),
                $count === 1
                    ? __('notifications.honor_board_body', ['category' => $first->category->label()])
                    : __('notifications.honor_board_batch_body', ['count' => $count]),
                $first->id,
                NotificationApp::Guardian,
            );
        }

        foreach (self::administrators($schoolId) as $admin) {
            NotificationGate::notify(
                $admin,
                'honor_board',
                $count === 1
                    ? __('notifications.honor_staff_title', ['name' => $names[0] ?? ''])
                    : __('notifications.honor_staff_batch_title', ['count' => $count]),
                __('notifications.honor_own_body', [
                    'reason' => $first->reason,
                    'subject' => $first->subject?->name ?? __('notifications.honor_general'),
                ]),
                $first->id,
                NotificationApp::Staff,
            );
        }
    }

    /**
     * @param  array<int, int>  $excludeUserIds
     * @return Collection<int, User>
     */
    private static function otherGuardians(int $schoolId, array $excludeUserIds): Collection
    {
        return User::query()
            ->where('school_id', $schoolId)
            ->where('role', UserRole::Guardian)
            ->where('status', Status::Active)
            ->whereNotIn('id', $excludeUserIds ?: [0])
            ->get();
    }

    /** @return Collection<int, User> */
    private static function administrators(int $schoolId): Collection
    {
        return User::query()
            ->where('school_id', $schoolId)
            ->whereIn('role', [UserRole::Admin->value, UserRole::SuperAdmin->value])
            ->where('status', Status::Active)
            ->get();
    }
}
