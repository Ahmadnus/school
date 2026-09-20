<?php

namespace App\Services;

use App\Enums\NotificationApp;
use App\Enums\Status;
use App\Enums\UserRole;
use App\Models\AbsenceExcuse;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who hears about an absence excuse.
 *
 * The excuse is a conversation with two turns, and before this class neither
 * turn was announced:
 *
 * - A guardian files an excuse from their app. Nothing reached the office, so
 *   it sat in the review queue until somebody thought to look — which is how
 *   an excused absence stays counted as unexcused for days.
 * - The office accepts or rejects it. Nothing reached the guardian, so the
 *   only way to learn the outcome was to reopen the app and check. The
 *   `excuse_reviewed` key existed in the catalog and in both language files,
 *   but no code ever sent it.
 *
 * Delivery follows AttendanceNotifier exactly: NotificationGate records it,
 * honours the school and personal switches, and fans out to Reverb and FCM.
 */
class ExcuseNotifier
{
    /** A guardian filed an excuse — the office is told it is waiting. */
    public static function submitted(AbsenceExcuse $excuse): void
    {
        $excuse->loadMissing('student');
        $student = $excuse->student;

        if (! $student) {
            return;
        }

        foreach (self::administrators($student->school_id) as $admin) {
            NotificationGate::notify(
                $admin,
                'excuse_submitted',
                __('notifications.excuse_submitted_title', ['name' => $student->full_name]),
                __('notifications.excuse_submitted_body', [
                    'from' => self::date($excuse->start_date),
                    'to' => self::date($excuse->end_date),
                    'reason' => $excuse->reason ?: __('notifications.excuse_no_reason'),
                ]),
                $excuse->id,
                NotificationApp::Staff,
            );
        }
    }

    /** The office decided — every guardian of that student hears the outcome. */
    public static function reviewed(AbsenceExcuse $excuse): void
    {
        $excuse->loadMissing('student.guardians.user');
        $student = $excuse->student;

        if (! $student) {
            return;
        }

        // The wording states the decision plainly; a rejected excuse read as
        // "reviewed" would leave a parent believing the absence was cleared.
        $accepted = $excuse->status->value === 'accepted';
        $body = __($accepted ? 'notifications.excuse_accepted_body' : 'notifications.excuse_rejected_body', [
            'from' => self::date($excuse->start_date),
            'to' => self::date($excuse->end_date),
        ]);

        if (filled($excuse->review_note)) {
            $body .= ' — '.$excuse->review_note;
        }

        foreach ($student->guardians as $guardian) {
            if (! $guardian->user) {
                continue;
            }

            NotificationGate::notify(
                $guardian->user,
                'excuse_reviewed',
                __('notifications.excuse_reviewed_title', ['name' => $student->full_name]),
                $body,
                $student->id,
                NotificationApp::Guardian,
            );
        }
    }

    private static function date(mixed $value): string
    {
        return $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d')
            : (string) $value;
    }

    private static function administrators(int $schoolId): Collection
    {
        return User::query()
            ->where('school_id', $schoolId)
            ->whereIn('role', [UserRole::Admin->value, UserRole::SuperAdmin->value])
            ->where('status', Status::Active)
            ->get();
    }
}
