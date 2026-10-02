<?php

namespace App\Services;

use App\Models\BehaviorRecord;
use App\Models\FeePlan;
use App\Models\FeePlanInstallment;
use App\Models\Guardian;
use App\Models\Notification;
use App\Models\ReportCard;

/**
 * What a notification points at, beyond its raw ref_id — so the apps can
 * deep-link (a behaviour record opens the student's profile, not a record).
 *
 * Resolved on demand and cached per request; the notification list is a
 * page of 20, so a lookup per behaviour row is cheap and avoids a column.
 */
class NotificationTarget
{
    /**
     * الجواب معلّقٌ بالكائن نفسه لا بمعرّفه: يموت معه، فلا يعيش في عامل
     * الطوابير أيّاماً ليُعاد عن صفٍّ آخر يحمل الرقم ذاته.
     *
     * @var \WeakMap<Notification, int|null>|null
     */
    private static ?\WeakMap $cache = null;

    public static function studentIdFor(Notification $notification): ?int
    {
        if ($notification->ref_id === null) {
            return null;
        }

        self::$cache ??= new \WeakMap;

        if (self::$cache->offsetExists($notification)) {
            return self::$cache[$notification];
        }

        return self::$cache[$notification] = self::resolve($notification);
    }

    /**
     * كل نوعٍ يحمل في `ref_id` شيئاً مختلفاً — الطالب نفسه، أو قسطاً، أو
     * جلاءً. وكان السلوك وحده يُحلّ إلى طالب، فيُنقر إشعار الغياب أو
     * التسميع ولا يُفتح شيء.
     */
    private static function resolve(Notification $notification): ?int
    {
        return match ($notification->type) {
            'attendance_absence', 'attendance_late', 'gate_arrival', 'tasmi_recorded',
            'excuse_reviewed', 'grade_published' => $notification->ref_id,
            'behavior_record' => BehaviorRecord::query()->whereKey($notification->ref_id)->value('student_id'),
            'report_card_published' => ReportCard::query()->whereKey($notification->ref_id)->value('student_id'),
            'fee_payment_recorded' => FeePlan::query()->whereKey($notification->ref_id)->value('student_id'),
            'fee_due' => self::feeDueStudent($notification),
            default => null,
        };
    }

    /**
     * `fee_due` يصدر من موضعين بمرجعين: التذكير اليوميّ يحمل **القسط** (به
     * يمنع تكرار نفسه)، وتذكير الإدارة اليدويّ يحمل **الطالب** (يجمع أقساطه
     * كلّها). الرقمان من جدولين فقد يتصادفان، فلا يُقبل إلّا طالبٌ هو فعلاً
     * ابنُ صاحب الإشعار — فلا يُفتح لأبٍ ابنُ غيره مهما تصادفت الأرقام.
     */
    private static function feeDueStudent(Notification $notification): ?int
    {
        $children = Guardian::query()
            ->where('user_id', $notification->user_id)
            ->with('students:id')
            ->get()
            ->flatMap(fn (Guardian $g) => $g->students->pluck('id'));

        $viaInstallment = FeePlan::query()
            ->whereKey(FeePlanInstallment::query()->whereKey($notification->ref_id)->value('fee_plan_id'))
            ->value('student_id');

        if ($viaInstallment !== null && $children->contains($viaInstallment)) {
            return $viaInstallment;
        }

        return $children->contains($notification->ref_id) ? $notification->ref_id : null;
    }
}
