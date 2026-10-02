<?php

namespace App\Services;

use App\Models\BehaviorRecord;
use App\Models\FeePlan;
use App\Models\FeePlanInstallment;
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
    /** @var array<string, int|null> */
    private static array $cache = [];

    public static function studentIdFor(Notification $notification): ?int
    {
        if ($notification->ref_id === null) {
            return null;
        }

        // كل نوعٍ يحمل في `ref_id` شيئاً مختلفاً — الطالب نفسه، أو قسطاً، أو
        // جلاءً. وكان السلوك وحده يُحلّ إلى طالب، فيُنقر إشعار الغياب أو
        // التسميع ولا يُفتح شيء.
        // المفتاح يحمل النوع والمرجع لا المعرّف وحده: الذاكرة ثابتةٌ تعيش
        // في عامل الطوابير أيّاماً، فلا يُعاد لها جوابٌ عن صفٍّ غير هذا.
        $key = "{$notification->id}:{$notification->type}:{$notification->ref_id}";

        return self::$cache[$key] ??= match ($notification->type) {
            'attendance_absence', 'attendance_late', 'gate_arrival', 'tasmi_recorded',
            'excuse_reviewed', 'grade_published' => $notification->ref_id,
            'behavior_record' => BehaviorRecord::query()->whereKey($notification->ref_id)->value('student_id'),
            'report_card_published' => ReportCard::query()->whereKey($notification->ref_id)->value('student_id'),
            'fee_payment_recorded' => FeePlan::query()->whereKey($notification->ref_id)->value('student_id'),
            // `ref_id` هنا قسطٌ لا خطّة — كان التطبيق يفتحه كأنّه خطّة.
            'fee_due' => FeePlan::query()
                ->whereKey(FeePlanInstallment::query()->whereKey($notification->ref_id)->value('fee_plan_id'))
                ->value('student_id'),
            default => null,
        };
    }
}
