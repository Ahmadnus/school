<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\StoreRubricRequest;
use App\Http\Requests\Academic\UpdateRubricRequest;
use App\Http\Resources\RubricResource;
use App\Models\Rubric;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class RubricController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Rubric::class);

        $rubrics = Rubric::query()
            ->ofSchool($request->user()->school_id)
            ->with('levels')
            ->withCount('levels')
            ->orderBy('name')
            ->get();

        return RubricResource::collection($rubrics);
    }

    public function store(StoreRubricRequest $request): JsonResponse
    {
        $this->authorize('create', Rubric::class);

        $rubric = DB::transaction(function () use ($request) {
            $rubric = Rubric::create([
                ...$request->safe()->except('levels'),
                'school_id' => $request->user()->school_id,
            ]);

            $this->replaceLevels($rubric, $request->input('levels', []));

            return $rubric;
        });

        return response()->json([
            'message' => __('messages.rubric.created'),
            'data' => new RubricResource($rubric->load('levels')),
        ], 201);
    }

    public function show(Rubric $rubric): RubricResource
    {
        $this->authorize('view', $rubric);

        return new RubricResource($rubric->load('levels'));
    }

    public function update(UpdateRubricRequest $request, Rubric $rubric): JsonResponse
    {
        $this->authorize('update', $rubric);

        DB::transaction(function () use ($request, $rubric) {
            $rubric->update($request->safe()->except('levels'));

            if ($request->has('levels')) {
                $this->replaceLevels($rubric, $request->input('levels', []));
            }
        });

        return response()->json([
            'message' => __('messages.rubric.updated'),
            'data' => new RubricResource($rubric->fresh('levels')),
        ]);
    }

    public function destroy(Rubric $rubric): JsonResponse
    {
        $this->authorize('delete', $rubric);

        $rubric->delete();

        return response()->json(['message' => __('messages.rubric.deleted')]);
    }

    /** Levels arrive as an ordered list, so their order is their position. */
    private function replaceLevels(Rubric $rubric, array $levels): void
    {
        $rubric->levels()->delete();

        foreach ($levels as $position => $level) {
            $rubric->levels()->create([
                'name' => $level['name'],
                'value' => $level['value'] ?? null,
                'sort_order' => $position + 1,
            ]);
        }
    }
}
