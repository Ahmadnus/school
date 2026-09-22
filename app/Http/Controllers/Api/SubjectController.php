<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\StoreSubjectRequest;
use App\Http\Requests\Academic\UpdateSubjectRequest;
use App\Http\Resources\SubjectResource;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SubjectController extends Controller
{
    /** The subjects screen always filters by grade picker + term switcher. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Subject::class);

        $subjects = Subject::query()
            ->ofSchool($request->user()->school_id)
            ->when($request->filled('grade_id'), fn ($q) => $q->where('grade_id', $request->integer('grade_id')))
            ->when($request->filled('term_id'), fn ($q) => $q->where('term_id', $request->integer('term_id')))
            ->with(['grade', 'term', 'feeType'])
            ->withCount('assignments')
            ->orderBy('name')
            ->get();

        return SubjectResource::collection($subjects);
    }

    public function store(StoreSubjectRequest $request): JsonResponse
    {
        $this->authorize('create', Subject::class);

        $subject = Subject::create($request->validated());

        return response()->json([
            'message' => __('messages.subject.created'),
            'data' => new SubjectResource($subject->load(['grade', 'term'])),
        ], 201);
    }

    public function show(Subject $subject): SubjectResource
    {
        $this->authorize('view', $subject);

        return new SubjectResource($subject->load(['grade', 'term', 'teachers']));
    }

    public function update(UpdateSubjectRequest $request, Subject $subject): JsonResponse
    {
        $this->authorize('update', $subject);

        $subject->update($request->validated());

        return response()->json([
            'message' => __('messages.subject.updated'),
            'data' => new SubjectResource($subject->fresh(['grade', 'term'])),
        ]);
    }

    public function destroy(Subject $subject): JsonResponse
    {
        $this->authorize('delete', $subject);

        if ($subject->assignments()->exists()) {
            return response()->json(['message' => __('messages.subject.has_assignments')], 422);
        }

        $subject->delete();

        return response()->json(['message' => __('messages.subject.deleted')]);
    }
}
