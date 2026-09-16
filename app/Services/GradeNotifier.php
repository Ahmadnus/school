<?php

namespace App\Services;

use App\Enums\NotificationApp;
use App\Enums\Status;
use App\Enums\UserRole;
use App\Models\Assessment;
use App\Models\GradeScore;
use App\Models\Section;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * ما يقوله نشر العلامات.
 *
 * - كل وليّ أمر يسمع علامة ابنه وحده، بصيغة واحدة مفهومة: «رياضيات — امتحان
 *   الفصل الأول: 18 من 20». لا يرى علامة طالب آخر أبداً.
 * - مشرفو الشعبة والإدارة يصلهم **كشف الشعبة كاملاً**: عدد المصحَّح، المعدّل،
 *   الأعلى، وعدد من هم تحت علامة النجاح بأسمائهم — لأن هذا ما يتصرّفون بناءً
 *   عليه.
 * - الأستاذ يصله تأكيد بما نُشر، حتى يعرف أن الأهل رأوه.
 *
 * ينشر التقييم غير المصحَّح لا يُشعر أحداً: العلامة الفارغة ليست خبراً.
 */
class GradeNotifier
{
    public static function published(Assessment $assessment): void
    {
        $assessment->loadMissing(['subject.grade.school', 'type', 'publisher']);

        $scores = $assessment->scores()
            ->whereNotNull('score')
            ->with(['student.guardians.user', 'student.currentEnrollment.section.grade'])
            ->get();

        if ($scores->isEmpty()) {
            return;
        }

        $schoolId = $assessment->subject->grade->school_id;
        $subjectName = $assessment->subject->name;

        self::notifyGuardians($assessment, $scores, $subjectName);
        self::notifySupervisors($assessment, $scores, $schoolId, $subjectName);
        self::notifyPublisher($assessment, $scores, $subjectName);
    }

    /** إشعار قادم: «امتحان رياضيات يوم كذا» قبل موعده. */
    public static function created(Assessment $assessment): void
    {
        $assessment->loadMissing(['subject.grade', 'type']);

        $guardians = self::guardiansOfGrade($assessment->subject->grade_id);

        foreach ($guardians as $user) {
            NotificationGate::notify(
                $user,
                'assessment_created',
                __('notifications.assessment_created_title', [
                    'subject' => $assessment->subject->name,
                ]),
                __('notifications.assessment_created_body', [
                    'name' => $assessment->name,
                    'type' => $assessment->type?->name ?? '',
                    'date' => $assessment->held_on?->translatedFormat('j F Y') ?? '—',
                ]),
                $assessment->id,
                NotificationApp::Guardian,
            );
        }
    }

    /** @param  Collection<int, GradeScore>  $scores */
    private static function notifyGuardians(Assessment $assessment, Collection $scores, string $subjectName): void
    {
        $max = rtrim(rtrim((string) $assessment->max_score, '0'), '.');

        foreach ($scores as $score) {
            $student = $score->student;

            if (! $student) {
                continue;
            }

            $value = rtrim(rtrim((string) $score->score, '0'), '.');

            foreach ($student->guardians as $guardian) {
                if (! $guardian->user) {
                    continue;
                }

                NotificationGate::notify(
                    $guardian->user,
                    'grade_published',
                    __('notifications.grade_published_title', [
                        'name' => $student->first_name,
                        'subject' => $subjectName,
                    ]),
                    __('notifications.grade_published_body', [
                        'assessment' => $assessment->name,
                        'score' => $value,
                        'max' => $max,
                    ]),
                    $student->id,
                    NotificationApp::Guardian,
                );
            }
        }
    }

    /**
     * كشف لكل شعبة إلى مشرفيها والإدارة.
     *
     * @param  Collection<int, GradeScore>  $scores
     */
    private static function notifySupervisors(
        Assessment $assessment,
        Collection $scores,
        int $schoolId,
        string $subjectName,
    ): void {
        $pass = (float) ($assessment->subject->pass_score ?? 0);
        $admins = self::administrators($schoolId);

        $bySection = $scores->groupBy(fn (GradeScore $s) => $s->student?->currentEnrollment?->section_id);

        foreach ($bySection as $sectionId => $rows) {
            $section = $sectionId ? Section::with('grade')->find($sectionId) : null;
            $label = $section
                ? trim(($section->grade?->name ?? '').' - '.$section->name, ' -')
                : __('notifications.no_section');

            $values = $rows->map(fn (GradeScore $s) => (float) $s->score);
            $below = $pass > 0
                ? $rows->filter(fn (GradeScore $s) => (float) $s->score < $pass)
                : collect();

            $body = __('notifications.grade_summary_body', [
                'count' => $rows->count(),
                'average' => number_format($values->avg(), 1, '.', ''),
                'highest' => rtrim(rtrim(number_format($values->max(), 2, '.', ''), '0'), '.'),
                'max' => rtrim(rtrim((string) $assessment->max_score, '0'), '.'),
            ]);

            if ($below->isNotEmpty()) {
                $names = $below->map(fn (GradeScore $s) => $s->student?->full_name)->filter()->take(10);
                $body .= ' — '.__('notifications.below_pass', ['count' => $below->count()])
                    .': '.$names->implode('، ').($below->count() > 10 ? '…' : '');
            }

            $recipients = $admins->merge($section?->supervisors ?? collect())
                ->unique('id')
                ->filter(fn (User $u) => $u->status === Status::Active);

            foreach ($recipients as $user) {
                NotificationGate::notify(
                    $user,
                    'grade_summary',
                    __('notifications.grade_summary_title', [
                        'subject' => $subjectName,
                        'section' => $label,
                    ]),
                    $body,
                    $assessment->id,
                    NotificationApp::Staff,
                );
            }
        }
    }

    /** @param  Collection<int, GradeScore>  $scores */
    private static function notifyPublisher(Assessment $assessment, Collection $scores, string $subjectName): void
    {
        $publisher = $assessment->publisher;

        if (! $publisher) {
            return;
        }

        NotificationGate::notify(
            $publisher,
            'grade_summary',
            __('notifications.grade_publish_confirm_title', ['subject' => $subjectName]),
            __('notifications.grade_publish_confirm_body', [
                'name' => $assessment->name,
                'count' => $scores->count(),
            ]),
            $assessment->id,
            NotificationApp::Staff,
        );
    }

    /** @return Collection<int, User> */
    private static function guardiansOfGrade(int $gradeId): Collection
    {
        return User::query()
            ->where('role', UserRole::Guardian)
            ->where('status', Status::Active)
            ->whereHas('guardian.students', fn ($s) => $s->inGrade($gradeId))
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
