<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\ReorderRequest;
use App\Http\Requests\Academic\StoreGradeRequest;
use App\Http\Requests\Academic\UpdateGradeRequest;
use App\Http\Resources\GradeResource;
use App\Http\Resources\StudentResource;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class GradeController extends Controller
{
    /** Grades with the sections of one academic year (the active one by default). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Grade::class);

        $schoolId = $request->user()->school_id;
        $yearId = $request->integer('academic_year_id') ?: AcademicYear::currentFor($schoolId)?->id;

        $grades = Grade::query()
            ->ofSchool($schoolId)
            ->with([
                'sections' => fn ($q) => $q->where('academic_year_id', $yearId)
                    ->withCount('enrollments')
                    ->ordered(),
            ])
            ->withCount(['sections' => fn ($q) => $q->where('academic_year_id', $yearId)])
            ->ordered()
            ->get();

        return GradeResource::collection($grades);
    }

    public function store(StoreGradeRequest $request): JsonResponse
    {
        $this->authorize('create', Grade::class);

        $schoolId = $request->user()->school_id;

        $grade = Grade::create([
            ...$request->validated(),
            'school_id' => $schoolId,
            'sort_order' => $request->input(
                'sort_order',
                (int) Grade::query()->ofSchool($schoolId)->max('sort_order') + 1,
            ),
        ]);

        return response()->json([
            'message' => __('messages.grade.created'),
            'data' => new GradeResource($grade),
        ], 201);
    }

    public function show(Grade $grade): GradeResource
    {
        $this->authorize('view', $grade);

        return new GradeResource($grade->load([
            'sections' => fn ($q) => $q->withCount('enrollments')->ordered(),
        ]));
    }

    public function update(UpdateGradeRequest $request, Grade $grade): JsonResponse
    {
        $this->authorize('update', $grade);

        $grade->update($request->validated());

        return response()->json([
            'message' => __('messages.grade.updated'),
            'data' => new GradeResource($grade->fresh()),
        ]);
    }

    public function destroy(Grade $grade): JsonResponse
    {
        $this->authorize('delete', $grade);

        if ($grade->sections()->exists()) {
            return response()->json(['message' => __('messages.grade.has_sections')], 422);
        }

        $grade->delete();

        return response()->json(['message' => __('messages.grade.deleted')]);
    }

    /** Drag-and-drop ordering of the grades list. */
    public function reorder(ReorderRequest $request): JsonResponse
    {
        $this->authorize('create', Grade::class);

        $ids = Grade::query()
            ->ofSchool($request->user()->school_id)
            ->whereIn('id', $request->input('ids'))
            ->pluck('id')
            ->all();

        DB::transaction(function () use ($request, $ids) {
            foreach ($request->input('ids') as $position => $id) {
                if (in_array((int) $id, $ids, true)) {
                    Grade::whereKey($id)->update(['sort_order' => $position + 1]);
                }
            }
        });

        return response()->json(['message' => __('messages.grade.reordered')]);
    }

    /** Students of a grade — current academic year only, via the enrollment table. */
    public function students(Request $request, Grade $grade): AnonymousResourceCollection
    {
        $this->authorize('view', $grade);

        $students = Student::query()
            ->ofSchool($grade->school_id)
            ->inGrade($grade->id)
            ->with('currentEnrollment.section.grade')
            ->orderBy('first_name')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return StudentResource::collection($students);
    }
}
