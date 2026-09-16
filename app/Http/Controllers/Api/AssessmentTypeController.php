<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\ReorderRequest;
use App\Http\Requests\Academic\StoreAssessmentTypeRequest;
use App\Http\Requests\Academic\UpdateAssessmentTypeRequest;
use App\Http\Resources\AssessmentTypeResource;
use App\Models\AssessmentType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class AssessmentTypeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AssessmentType::class);

        $types = AssessmentType::query()
            ->ofSchool($request->user()->school_id)
            ->withCount('assessments')
            ->ordered()
            ->get();

        return AssessmentTypeResource::collection($types);
    }

    public function store(StoreAssessmentTypeRequest $request): JsonResponse
    {
        $this->authorize('create', AssessmentType::class);

        $schoolId = $request->user()->school_id;

        $type = AssessmentType::create([
            ...$request->validated(),
            'school_id' => $schoolId,
            'sort_order' => $request->input(
                'sort_order',
                (int) AssessmentType::query()->ofSchool($schoolId)->max('sort_order') + 1,
            ),
        ]);

        return response()->json([
            'message' => __('messages.assessment_type.created'),
            'data' => new AssessmentTypeResource($type),
        ], 201);
    }

    public function show(AssessmentType $assessmentType): AssessmentTypeResource
    {
        $this->authorize('view', $assessmentType);

        return new AssessmentTypeResource($assessmentType->loadCount('assessments'));
    }

    public function update(UpdateAssessmentTypeRequest $request, AssessmentType $assessmentType): JsonResponse
    {
        $this->authorize('update', $assessmentType);

        $assessmentType->update($request->validated());

        return response()->json([
            'message' => __('messages.assessment_type.updated'),
            'data' => new AssessmentTypeResource($assessmentType->fresh()),
        ]);
    }

    public function destroy(AssessmentType $assessmentType): JsonResponse
    {
        $this->authorize('delete', $assessmentType);

        if ($assessmentType->assessments()->exists()) {
            return response()->json(['message' => __('messages.assessment_type.in_use')], 422);
        }

        $assessmentType->delete();

        return response()->json(['message' => __('messages.assessment_type.deleted')]);
    }

    /**
     * Drag-and-drop ordering of the categories, mirroring grades and sections.
     *
     * Positions are rewritten from the submitted order; ids outside the
     * caller's school are ignored rather than rejected.
     */
    public function reorder(ReorderRequest $request): JsonResponse
    {
        $this->authorize('create', AssessmentType::class);

        $ids = AssessmentType::query()
            ->ofSchool($request->user()->school_id)
            ->whereIn('id', $request->input('ids'))
            ->pluck('id')
            ->all();

        DB::transaction(function () use ($request, $ids) {
            foreach ($request->input('ids') as $position => $id) {
                if (in_array((int) $id, $ids, true)) {
                    AssessmentType::whereKey($id)->update(['sort_order' => $position + 1]);
                }
            }
        });

        return response()->json(['message' => __('messages.assessment_type.reordered')]);
    }
}
