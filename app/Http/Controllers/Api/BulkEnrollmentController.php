<?php

namespace App\Http\Controllers\Api;

use App\Enums\EnrollmentScope;
use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Student\BulkEnrollRequest;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Bulk enrollment — the yearly "promote the whole section" chore done in one
 * step instead of one student at a time.
 *
 * Two calls with the same body: `preview` shows exactly what would happen
 * (who gets enrolled, who is skipped and why, whether capacity allows it) and
 * `store` applies it. Nothing is written without an explicit second call, and
 * existing enrollments are never overwritten — a student who already has an
 * enrollment in the target year is reported as skipped.
 */
class BulkEnrollmentController extends Controller
{
    public function preview(BulkEnrollRequest $request): JsonResponse
    {
        $this->authorize('create', Student::class);

        return response()->json(['data' => $this->plan($request)]);
    }

    public function store(BulkEnrollRequest $request): JsonResponse
    {
        $this->authorize('create', Student::class);

        $plan = $this->plan($request);

        if ($plan['over_capacity']) {
            return response()->json(['message' => __('messages.section.capacity_reached'), 'data' => $plan], 422);
        }

        $data = $request->validated();
        $section = Section::findOrFail($data['section_id']);

        DB::transaction(function () use ($plan, $section, $data) {
            foreach ($plan['to_enroll'] as $row) {
                StudentEnrollment::create([
                    'student_id' => $row['id'],
                    'section_id' => $section->id,
                    'academic_year_id' => $section->academic_year_id,
                    'scope' => $data['scope'] ?? EnrollmentScope::FullYear->value,
                    'enrolled_at' => $data['enrolled_at'] ?? now()->toDateString(),
                    'transport_subscribed' => (bool) ($data['transport_subscribed'] ?? false),
                    'status' => EnrollmentStatus::Active,
                ]);
            }
        });

        return response()->json([
            'message' => __('messages.enrollment.bulk_created', ['count' => count($plan['to_enroll'])]),
            'data' => $plan,
        ], 201);
    }

    /**
     * @return array{section: array, to_enroll: array, skipped: array, capacity: ?int, current_count: int, over_capacity: bool}
     */
    private function plan(BulkEnrollRequest $request): array
    {
        $data = $request->validated();
        $schoolId = $request->user()->school_id;

        $section = Section::with('grade', 'academicYear')->findOrFail($data['section_id']);

        $students = Student::query()
            ->ofSchool($schoolId)
            ->whereIn('id', $data['student_ids'])
            ->with(['enrollments' => fn ($q) => $q->where('academic_year_id', $section->academic_year_id)->with('section')])
            ->orderBy('first_name')
            ->get();

        $toEnroll = [];
        $skipped = [];

        foreach ($students as $student) {
            $existing = $student->enrollments->first();

            if ($existing) {
                $skipped[] = [
                    'id' => $student->id,
                    'full_name' => $student->full_name,
                    'reason' => $existing->section_id === $section->id
                        ? __('messages.enrollment.already_in_section')
                        : __('messages.enrollment.already_enrolled_year', ['section' => $existing->section?->name ?? '']),
                ];
                continue;
            }

            $toEnroll[] = ['id' => $student->id, 'full_name' => $student->full_name];
        }

        $currentCount = $section->enrollments()->where('status', EnrollmentStatus::Active)->count();
        $capacity = $section->capacity;

        return [
            'section' => [
                'id' => $section->id,
                'name' => $section->name,
                'grade' => $section->grade?->name,
                'academic_year' => $section->academicYear?->name,
            ],
            'to_enroll' => $toEnroll,
            'skipped' => $skipped,
            'capacity' => $capacity,
            'current_count' => $currentCount,
            'over_capacity' => $capacity !== null && $capacity > 0 && ($currentCount + count($toEnroll)) > $capacity,
        ];
    }
}
