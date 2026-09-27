<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\StoreTeacherAssignmentRequest;
use App\Http\Resources\TeacherAssignmentResource;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TeacherAssignmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TeacherAssignment::class);

        $assignments = TeacherAssignment::query()
            ->ofSchool($request->user()->school_id)
            ->when($request->filled('staff_id'), fn ($q) => $q->where('staff_id', $request->integer('staff_id')))
            ->when($request->filled('subject_id'), fn ($q) => $q->where('subject_id', $request->integer('subject_id')))
            ->when($request->filled('section_id'), fn ($q) => $q->where('section_id', $request->integer('section_id')))
            ->with(['teacher', 'subject.grade', 'subject.term', 'section.grade'])
            ->get();

        return TeacherAssignmentResource::collection($assignments);
    }

    /**
     * Assign a teacher to a subject in a section. Reachable from the staff form
     * and from the "assign to this subject" button on the schedule screen.
     */
    public function store(StoreTeacherAssignmentRequest $request): JsonResponse
    {
        $this->authorize('create', TeacherAssignment::class);

        $data = $request->validated();
        $subject = Subject::with('term')->findOrFail($data['subject_id']);
        $section = Section::findOrFail($data['section_id']);

        if ($section->grade_id !== $subject->grade_id) {
            return response()->json(['message' => __('messages.assignment.grade_mismatch')], 422);
        }

        if ($section->academic_year_id !== $subject->term->academic_year_id) {
            return response()->json(['message' => __('messages.assignment.term_mismatch')], 422);
        }

        $assignment = TeacherAssignment::create($data);

        return response()->json([
            'message' => __('messages.assignment.created'),
            'data' => new TeacherAssignmentResource(
                $assignment->load(['teacher', 'subject.grade', 'section.grade']),
            ),
        ], 201);
    }

    public function destroy(TeacherAssignment $assignment): JsonResponse
    {
        $this->authorize('delete', $assignment);

        $assignment->delete();

        return response()->json(['message' => __('messages.assignment.deleted')]);
    }

    /**
     * يضبط عدد حصص الإسناد في الأسبوع — خطوة «مراجعة الإسنادات» قبل التوليد.
     *
     * منفصلٌ عن إنشاء الإسناد: من يراجع الجدول قبل توليده يصحّح أعداداً على
     * إسنادات قائمة، ولا ينشئها من جديد. وإفراغ الحقل يُرجعه إلى ما على
     * المادة بدل أن يصير صفراً — والصفر يُسقط المادة من الجدول صامتاً.
     */
    public function update(Request $request, TeacherAssignment $assignment): JsonResponse
    {
        $this->authorize('update', $assignment);

        $data = $request->validate([
            'lessons_per_week' => ['present', 'nullable', 'integer', 'min:1', 'max:40'],
        ]);

        $assignment->update(['lessons_per_week' => $data['lessons_per_week']]);

        return response()->json([
            'message' => __('timetable.messages.assignment_updated'),
            'data' => new TeacherAssignmentResource(
                $assignment->fresh(['teacher', 'subject.grade', 'section.grade']),
            ),
        ]);
    }
}
