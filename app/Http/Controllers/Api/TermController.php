<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\StoreTermRequest;
use App\Http\Requests\Academic\UpdateTermRequest;
use App\Http\Resources\TermResource;
use App\Models\AcademicYear;
use App\Models\Term;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TermController extends Controller
{
    public function index(AcademicYear $academicYear): AnonymousResourceCollection
    {
        $this->authorize('view', $academicYear);

        return TermResource::collection(
            $academicYear->terms()->orderBy('start_date')->get(),
        );
    }

    public function store(StoreTermRequest $request, AcademicYear $academicYear): JsonResponse
    {
        $this->authorize('update', $academicYear);

        $term = $academicYear->terms()->create($request->safe()->except('is_current'));

        if ($request->boolean('is_current')) {
            $term->markAsCurrent();
        }

        return response()->json([
            'message' => __('messages.term.created'),
            'data' => new TermResource($term->fresh()),
        ], 201);
    }

    public function update(UpdateTermRequest $request, Term $term): JsonResponse
    {
        $this->authorize('update', $term);

        $term->update($request->safe()->except('is_current'));

        if ($request->boolean('is_current')) {
            $term->markAsCurrent();
        }

        return response()->json([
            'message' => __('messages.term.updated'),
            'data' => new TermResource($term->fresh()),
        ]);
    }

    public function markCurrent(Term $term): JsonResponse
    {
        $this->authorize('update', $term);

        $term->markAsCurrent();

        return response()->json([
            'message' => __('messages.term.marked_current'),
            'data' => new TermResource($term->fresh()),
        ]);
    }

    public function destroy(Term $term): JsonResponse
    {
        $this->authorize('delete', $term);

        $term->delete();

        return response()->json(['message' => __('messages.term.deleted')]);
    }
}
