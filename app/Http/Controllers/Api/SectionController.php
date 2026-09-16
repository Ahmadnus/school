<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\ReorderRequest;
use App\Http\Requests\Academic\StoreSectionRequest;
use App\Http\Requests\Academic\UpdateSectionRequest;
use App\Http\Resources\SectionResource;
use App\Http\Resources\StudentResource;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\Section;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class SectionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Section::class);

        $schoolId = $request->user()->school_id;
        $yearId = $request->integer('academic_year_id') ?: AcademicYear::currentFor($schoolId)?->id;

        $sections = Section::query()
            ->whereHas('grade', fn ($q) => $q->where('school_id', $schoolId))
            ->when($yearId, fn ($q) => $q->ofYear($yearId))
            ->when($request->filled('grade_id'), fn ($q) => $q->where('grade_id', $request->integer('grade_id')))
            ->with(['grade', 'academicYear'])
            ->withCount('enrollments')
            ->ordered()
            ->get();

        return SectionResource::collection($sections);
    }

    public function store(StoreSectionRequest $request): JsonResponse
    {
        $this->authorize('create', Section::class);

        $data = $request->validated();

        $section = Section::create([
            ...$data,
            'sort_order' => $data['sort_order'] ?? (int) Section::query()
                ->where('grade_id', $data['grade_id'])
                ->where('academic_year_id', $data['academic_year_id'])
                ->max('sort_order') + 1,
        ]);

        return response()->json([
            'message' => __('messages.section.created'),
            'data' => new SectionResource($section->load('grade')),
        ], 201);
    }

    public function show(Section $section): SectionResource
    {
        $this->authorize('view', $section);

        return new SectionResource($section->load(['grade', 'academicYear'])->loadCount('enrollments'));
    }

    public function update(UpdateSectionRequest $request, Section $section): JsonResponse
    {
        $this->authorize('update', $section);

        $section->update($request->validated());

        return response()->json([
            'message' => __('messages.section.updated'),
            'data' => new SectionResource($section->fresh(['grade'])),
        ]);
    }

    public function destroy(Section $section): JsonResponse
    {
        $this->authorize('delete', $section);

        if ($section->enrollments()->exists()) {
            return response()->json(['message' => __('messages.section.has_students')], 422);
        }

        $section->delete();

        return response()->json(['message' => __('messages.section.deleted')]);
    }

    /** Drag-and-drop ordering of the sections inside one grade card. */
    public function reorder(ReorderRequest $request, Grade $grade): JsonResponse
    {
        $this->authorize('update', $grade);

        $ids = $grade->sections()->whereIn('id', $request->input('ids'))->pluck('id')->all();

        DB::transaction(function () use ($request, $ids) {
            foreach ($request->input('ids') as $position => $id) {
                if (in_array((int) $id, $ids, true)) {
                    Section::whereKey($id)->update(['sort_order' => $position + 1]);
                }
            }
        });

        return response()->json(['message' => __('messages.section.reordered')]);
    }

    /** Students of a section — current academic year only, via the enrollment table. */
    public function students(Request $request, Section $section): AnonymousResourceCollection
    {
        $this->authorize('view', $section);

        $students = Student::query()
            ->ofSchool($section->grade->school_id)
            ->inSection($section->id)
            ->with('currentEnrollment.section.grade')
            ->orderBy('first_name')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return StudentResource::collection($students);
    }
}
