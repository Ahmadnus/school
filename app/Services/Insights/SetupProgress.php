<?php

namespace App\Services\Insights;

use App\Enums\AttendanceSessionStatus;
use App\Enums\UserRole;
use App\Models\AttendanceSession;
use App\Models\FeeType;
use App\Models\SchoolNotificationSetting;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;

/**
 * How far a new school has come in setting itself up. Each step is a real
 * check against the data, so the percentage is never stale or invented.
 */
class SetupProgress
{
    /** @return array{percent:int, steps: array<int, array<string, mixed>>} */
    public static function for(InsightContext $ctx): array
    {
        $schoolId = $ctx->school->id;
        $school = $ctx->school;

        $checks = [
            'school_info' => ['done' => filled($school->name) && filled($school->phone) && filled($school->currency), 'route' => 'schoolSettings'],
            'academic_year' => ['done' => $ctx->year !== null, 'route' => 'academicYears'],
            'terms' => ['done' => $ctx->year && Term::query()->where('academic_year_id', $ctx->year->id)->exists(), 'route' => 'academicYears'],
            'grades_sections' => ['done' => $ctx->year && Section::query()->where('academic_year_id', $ctx->year->id)->whereHas('grade', fn ($g) => $g->where('school_id', $schoolId))->exists(), 'route' => 'gradesSections'],
            'subjects' => ['done' => $ctx->term && Subject::query()->where('term_id', $ctx->term->id)->exists(), 'route' => 'subjects'],
            'staff' => ['done' => User::query()->where('school_id', $schoolId)->where('role', UserRole::Teacher->value)->exists(), 'route' => 'staff'],
            'students' => ['done' => Student::query()->ofSchool($schoolId)->exists(), 'route' => 'students'],
            'guardians' => ['done' => StudentGuardian::query()->whereHas('student', fn ($s) => $s->ofSchool($schoolId))->exists(), 'route' => 'guardians'],
            'fee_types' => ['done' => FeeType::query()->where('school_id', $schoolId)->exists(), 'route' => 'feeTypes'],
            'notifications' => ['done' => SchoolNotificationSetting::query()->ofSchool($schoolId)->exists(), 'route' => 'notificationManagement'],
            'roll_call' => ['done' => AttendanceSession::query()->where('status', AttendanceSessionStatus::Submitted->value)->whereHas('section.grade', fn ($g) => $g->where('school_id', $schoolId))->exists(), 'route' => 'takeAttendance'],
        ];

        $steps = [];
        $done = 0;
        foreach ($checks as $key => $check) {
            $steps[] = [
                'key' => $key,
                'title' => __('insights.setup.'.$key),
                'done' => (bool) $check['done'],
                'route' => $check['route'],
            ];
            if ($check['done']) {
                $done++;
            }
        }

        return [
            'percent' => (int) round($done / count($checks) * 100),
            'steps' => $steps,
        ];
    }
}
