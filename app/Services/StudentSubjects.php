<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\GradeScore;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\Term;

/**
 * "What is this student studying right now, with whom, and how are they doing?"
 *
 * There is no student↔subject table by design: a subject belongs to a grade
 * and a term, and the student reaches it through the enrollment's section
 * (Student → Enrollment → Section → Grade → Subjects of the term). The
 * teacher comes from teacher_assignments on (subject, section), and the
 * running score reuses the exact weighting of ReportCardBuilder so the
 * profile and the report card never disagree.
 */
class StudentSubjects
{
    /**
     * @return array{enrollment: ?StudentEnrollment, term: ?Term, subjects: array<int, array<string, mixed>>}
     */
    public static function for(Student $student, ?int $termId = null): array
    {
        $enrollment = $student->currentEnrollment()->with('section.grade', 'academicYear')->first();

        if (! $enrollment) {
            return ['enrollment' => null, 'term' => null, 'subjects' => []];
        }

        $terms = Term::query()->where('academic_year_id', $enrollment->academic_year_id);

        $term = $termId
            ? (clone $terms)->find($termId)
            : ((clone $terms)->current()->first() ?? (clone $terms)->orderBy('start_date')->first());

        if (! $term) {
            return ['enrollment' => $enrollment, 'term' => null, 'subjects' => []];
        }

        $subjects = Subject::query()
            ->forGradeAndTerm($enrollment->section->grade_id, $term->id)
            ->orderBy('name')
            ->get();

        $teachers = TeacherAssignment::query()
            ->where('section_id', $enrollment->section_id)
            ->whereIn('subject_id', $subjects->pluck('id'))
            ->with('teacher')
            ->get()
            ->keyBy('subject_id');

        $assessments = Assessment::query()
            ->whereIn('subject_id', $subjects->pluck('id'))
            ->with('type')
            ->orderBy('id')
            ->get()
            ->groupBy('subject_id');

        $scores = GradeScore::query()
            ->where('student_id', $student->id)
            ->whereIn('assessment_id', $assessments->flatten()->pluck('id'))
            ->get()
            ->keyBy('assessment_id');

        $rows = [];

        foreach ($subjects as $subject) {
            $subjectAssessments = $assessments->get($subject->id, collect());
            $items = [];

            foreach ($subjectAssessments as $assessment) {
                $score = $scores->get($assessment->id)?->score;
                $items[] = [
                    'id' => $assessment->id,
                    'name' => $assessment->name,
                    'type' => $assessment->type?->name,
                    'is_exam' => (bool) ($assessment->type?->is_exam ?? false),
                    'max_score' => (float) $assessment->max_score,
                    // الوزن الفعّال: وزن الورقة إن كُتب، وإلّا وزن نوعها —
                    // فترى الشاشة ما يدخل الحساب لا ما هو مخزَّن عليها.
                    'weight_percent' => $assessment->weight_percent !== null && (float) $assessment->weight_percent > 0
                        ? (float) $assessment->weight_percent
                        : (($assessment->type?->weight_percent ?? null) === null
                            ? null
                            : (float) $assessment->type->weight_percent),
                    'score' => $score === null ? null : (float) $score,
                ];
            }

            $percent = SubjectGrade::percent($subjectAssessments, $scores);
            $value = $percent === null ? null : round($percent * (float) $subject->max_score / 100, 2);
            $teacher = $teachers->get($subject->id)?->teacher;

            $rows[] = [
                'id' => $subject->id,
                'name' => $subject->name,
                'grading_method' => $subject->grading_method->value,
                'grading_method_label' => $subject->grading_method->label(),
                'max_score' => (float) $subject->max_score,
                'pass_score' => (float) $subject->pass_score,
                'periods_per_week' => $subject->periods_per_week,
                'teacher' => $teacher ? [
                    'id' => $teacher->id,
                    'full_name' => $teacher->full_name,
                    'specialty' => $teacher->specialty,
                ] : null,
                'current_score' => $value,
                'current_percent' => $percent === null ? null : round($percent, 1),
                'passed' => $value === null ? null : $value >= (float) $subject->pass_score,
                'assessments_count' => $subjectAssessments->count(),
                'scored_count' => count(array_filter($items, fn ($i) => $i['score'] !== null)),
                'assessments' => $items,
            ];
        }

        return ['enrollment' => $enrollment, 'term' => $term, 'subjects' => $rows];
    }
}
