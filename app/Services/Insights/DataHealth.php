<?php

namespace App\Services\Insights;

use App\Enums\Status;
use App\Models\Assessment;
use App\Models\Guardian;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use Illuminate\Support\Facades\DB;

/**
 * Incomplete or inconsistent records an administrator should look at. Counts
 * only — nothing is modified; every issue points at the screen that fixes it.
 */
class DataHealth
{
    /** @return array<int, array<string, mixed>> */
    public static function for(InsightContext $ctx): array
    {
        $schoolId = $ctx->school->id;
        $issues = [];

        $push = function (string $key, int $count, string $route, array $params = []) use (&$issues) {
            if ($count > 0) {
                $issues[] = [
                    'key' => $key,
                    'count' => $count,
                    'title' => __('insights.data_health.'.$key),
                    'route' => $route,
                    'params' => $params,
                ];
            }
        };

        $push('no_current_year', $ctx->year ? 0 : 1, 'academicYears');
        $push('no_current_term', $ctx->year && ! $ctx->term ? 1 : 0, 'academicYears');

        $push('students_without_guardian', Student::query()
            ->ofSchool($schoolId)->where('status', Status::Active)
            ->whereDoesntHave('guardians')->count(), 'students');

        if ($ctx->year) {
            $push('students_not_enrolled', Student::query()
                ->ofSchool($schoolId)->where('status', Status::Active)
                ->whereDoesntHave('currentEnrollment')->count(), 'students');

            $push('sections_without_teacher', Section::query()
                ->where('academic_year_id', $ctx->year->id)
                ->whereHas('grade', fn ($g) => $g->where('school_id', $schoolId))
                ->whereNotIn('id', TeacherAssignment::query()->select('section_id'))
                ->count(), 'staff');
        }

        if ($ctx->term) {
            $push('subjects_without_assessments', Subject::query()
                ->where('term_id', $ctx->term->id)
                ->whereHas('grade', fn ($g) => $g->where('school_id', $schoolId))
                ->whereNotIn('id', Assessment::query()->select('subject_id'))
                ->count(), 'assessments');
        }

        $push('guardians_without_phone', Guardian::query()
            ->ofSchool($schoolId)
            ->where(fn ($q) => $q->whereNull('phone')->orWhere('phone', ''))
            ->count(), 'guardians');

        $push('students_missing_birth_date', Student::query()
            ->ofSchool($schoolId)->where('status', Status::Active)
            ->whereNull('birth_date')->count(), 'students');

        $duplicates = Student::query()
            ->ofSchool($schoolId)
            ->select('first_name', 'last_name', DB::raw('count(*) as c'))
            ->groupBy('first_name', 'last_name')
            ->having('c', '>', 1)
            ->get()
            ->sum(fn ($row) => (int) $row->c);
        $push('duplicate_students', (int) $duplicates, 'students');

        return $issues;
    }
}
