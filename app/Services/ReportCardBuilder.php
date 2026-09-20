<?php

namespace App\Services;

use App\Enums\NotificationApp;
use App\Enums\ReportCardStatus;
use App\Models\Assessment;
use App\Models\GradeScore;
use App\Models\ReportCard;
use App\Models\Subject;
use Illuminate\Support\Facades\DB;

/**
 * A draft report card is computed live from grades_scores; the lines and the
 * average are frozen into report_card_lines only at publish (decision 11-c).
 * That is why a draft shows "المعدّل: –" — there is nothing stored yet.
 */
class ReportCardBuilder
{
    /**
     * The live sheet for a draft: one row per subject of the term, weighted by
     * each assessment's weight_percent where set.
     *
     * @return array{lines: array<int, array{subject_id:int, subject:string, score:?float, max_score:float}>, average: ?float}
     */
    public static function compute(ReportCard $card): array
    {
        $card->loadMissing('student');

        $subjects = Subject::query()
            ->where('term_id', $card->term_id)
            ->whereIn('grade_id', self::gradeIdsFor($card))
            ->orderBy('name')
            ->get();

        $assessments = Assessment::query()
            ->whereIn('subject_id', $subjects->pluck('id'))
            ->get()
            ->groupBy('subject_id');

        $scores = GradeScore::query()
            ->where('student_id', $card->student_id)
            ->whereIn('assessment_id', $assessments->flatten()->pluck('id'))
            ->whereNotNull('score')
            ->get()
            ->keyBy('assessment_id');

        $lines = [];
        $weighted = [];

        foreach ($subjects as $subject) {
            $subjectAssessments = $assessments->get($subject->id, collect());
            $totalWeight = 0.0;
            $earned = 0.0;
            $any = false;

            foreach ($subjectAssessments as $assessment) {
                $score = $scores->get($assessment->id)?->score;

                if ($score === null) {
                    continue;
                }

                $any = true;
                // An unweighted assessment counts as an equal share.
                $weight = (float) $assessment->weight_percent ?: 1.0;
                $totalWeight += $weight;
                $earned += ((float) $score / (float) $assessment->max_score) * $weight;
            }

            $percent = $any && $totalWeight > 0 ? ($earned / $totalWeight) * 100 : null;
            $value = $percent === null ? null : round($percent * (float) $subject->max_score / 100, 2);

            $lines[] = [
                'subject_id' => $subject->id,
                'subject' => $subject->name,
                'score' => $value,
                'max_score' => (float) $subject->max_score,
                'passed' => $value === null ? null : $value >= (float) $subject->pass_score,
            ];

            if ($percent !== null) {
                $weighted[] = $percent;
            }
        }

        return [
            'lines' => $lines,
            'average' => $weighted === [] ? null : round(array_sum($weighted) / count($weighted), 2),
        ];
    }

    /** Freeze the computed sheet onto the card and publish it. */
    public static function publish(ReportCard $card): ReportCard
    {
        $computed = self::compute($card);

        DB::transaction(function () use ($card, $computed) {
            $card->lines()->delete();

            foreach ($computed['lines'] as $position => $line) {
                $card->lines()->create([
                    'subject_id' => $line['subject_id'],
                    'score' => $line['score'],
                    'grade_label' => $line['score'] === null ? null : self::labelFor($line),
                    'sort_order' => $position + 1,
                ]);
            }

            $card->update([
                'average' => $computed['average'],
                'status' => ReportCardStatus::Published,
                'published_at' => now(),
            ]);
        });

        $published = $card->fresh('lines');

        // `report_card_published` كان في فهرس الإشعارات ومفاتيحه مترجمة، ولا
        // يرسله أحد: تصدر الشهادة فلا يعلم الأهل حتى يفتحوا التطبيق مصادفةً.
        self::notifyGuardians($published);

        return $published;
    }

    /** الشهادة تخصّ طالباً واحداً، فتذهب إلى أولياء أمره وحدهم. */
    private static function notifyGuardians(ReportCard $card): void
    {
        $card->loadMissing(['student.guardians.user', 'term']);
        $student = $card->student;

        if (! $student) {
            return;
        }

        $term = $card->term?->name ?? '';
        $body = $card->average === null
            ? __('notifications.report_card_body_no_average', ['term' => $term])
            : __('notifications.report_card_body', [
                'term' => $term,
                'average' => $card->average,
            ]);

        foreach ($student->guardians as $guardian) {
            if (! $guardian->user) {
                continue;
            }

            NotificationGate::notify(
                $guardian->user,
                'report_card_published',
                __('notifications.report_card_title', ['name' => $student->full_name]),
                $body,
                $card->id,
                NotificationApp::Guardian,
            );
        }
    }

    /** @return array<int, int> */
    private static function gradeIdsFor(ReportCard $card): array
    {
        $enrollment = $card->student->enrollments()
            ->where('academic_year_id', $card->academic_year_id)
            ->with('section')
            ->first();

        return $enrollment ? [$enrollment->section->grade_id] : [];
    }

    private static function labelFor(array $line): string
    {
        $percent = $line['max_score'] > 0 ? ($line['score'] / $line['max_score']) * 100 : 0;

        return match (true) {
            $percent >= 90 => __('grade_labels.excellent'),
            $percent >= 80 => __('grade_labels.very_good'),
            $percent >= 65 => __('grade_labels.good'),
            $percent >= 50 => __('grade_labels.acceptable'),
            default => __('grade_labels.weak'),
        };
    }
}
