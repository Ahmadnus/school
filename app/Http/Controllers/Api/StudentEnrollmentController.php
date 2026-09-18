<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\StoreEnrollmentRequest;
use App\Http\Requests\Student\UpdateEnrollmentRequest;
use App\Http\Resources\StudentEnrollmentResource;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Services\EnrollmentFeePlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StudentEnrollmentController extends Controller
{
    /** A student's enrollment history, newest year first. */
    public function index(Student $student): AnonymousResourceCollection
    {
        $this->authorize('view', $student);

        $enrollments = $student->enrollments()
            ->with(['section.grade', 'academicYear'])
            ->join('academic_years', 'academic_years.id', '=', 'student_enrollments.academic_year_id')
            ->orderByDesc('academic_years.start_date')
            ->select('student_enrollments.*')
            ->get();

        return StudentEnrollmentResource::collection($enrollments);
    }

    public function store(StoreEnrollmentRequest $request, Student $student): JsonResponse
    {
        $this->authorize('enroll', $student);

        $data = $request->validated();
        $section = Section::findOrFail($data['section_id']);

        if ($section->academic_year_id !== (int) $data['academic_year_id']) {
            return response()->json(['message' => __('messages.section.year_mismatch')], 422);
        }

        if ($this->isFull($section)) {
            return response()->json(['message' => __('messages.section.capacity_reached')], 422);
        }

        $enrollment = $student->enrollments()->create($data);

        // الإسناد إلى صف يعني قسط ذلك الصف — بلا شاشة ثانية.
        EnrollmentFeePlanner::ensureFor($enrollment);

        return response()->json([
            'message' => __('messages.enrollment.created'),
            'data' => new StudentEnrollmentResource($enrollment->load(['section.grade', 'academicYear'])),
        ], 201);
    }

    public function update(UpdateEnrollmentRequest $request, StudentEnrollment $enrollment): JsonResponse
    {
        $this->authorize('enroll', $enrollment->student);

        $data = $request->validated();

        if ($sectionId = $data['section_id'] ?? null) {
            $section = Section::findOrFail($sectionId);

            // A move stays inside the same academic year.
            if ($section->academic_year_id !== $enrollment->academic_year_id) {
                return response()->json(['message' => __('messages.section.year_mismatch')], 422);
            }

            if ($section->id !== $enrollment->section_id && $this->isFull($section)) {
                return response()->json(['message' => __('messages.section.capacity_reached')], 422);
            }
        }

        $enrollment->update($data);

        return response()->json([
            'message' => __('messages.enrollment.updated'),
            'data' => new StudentEnrollmentResource($enrollment->fresh(['section.grade', 'academicYear'])),
        ]);
    }

    public function destroy(StudentEnrollment $enrollment): JsonResponse
    {
        $this->authorize('enroll', $enrollment->student);

        $enrollment->delete();

        return response()->json(['message' => __('messages.enrollment.deleted')]);
    }

    private function isFull(Section $section): bool
    {
        return $section->capacity !== null
            && $section->enrollments()->count() >= $section->capacity;
    }
}
