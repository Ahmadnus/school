<?php

namespace App\Http\Controllers\Api;

use App\Enums\ExcuseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\ReviewExcuseRequest;
use App\Http\Requests\Attendance\StoreExcuseRequest;
use App\Http\Resources\AbsenceExcuseResource;
use App\Models\AbsenceExcuse;
use App\Models\Student;
use App\Services\ExcuseNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AbsenceExcuseController extends Controller
{
    /** The review screen defaults to the pending queue. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AbsenceExcuse::class);

        $excuses = AbsenceExcuse::query()
            ->ofSchool($request->user()->school_id)
            ->when(
                $request->user()->role->isGuardian(),
                fn ($q) => $q->whereHas('student.guardians', fn ($g) => $g->where('guardians.user_id', $request->user()->id)),
            )
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->when($request->filled('date'), fn ($q) => $q->covering($request->date('date')->toDateString()))
            ->with(['student.currentEnrollment.section.grade', 'reviewer'])
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return AbsenceExcuseResource::collection($excuses);
    }

    /**
     * The spec expects excuses to arrive from the guardian app, which is out of
     * scope; this endpoint is what that app (or a supervisor) posts to.
     */
    public function store(StoreExcuseRequest $request): JsonResponse
    {
        // The office files for anyone; a guardian only for their own child.
        $this->authorize('submitExcuse', Student::findOrFail($request->integer('student_id')));

        $excuse = AbsenceExcuse::create($request->validated());

        // Without this the excuse waits silently in the review queue, and the
        // absence keeps counting as unexcused until somebody happens to look.
        ExcuseNotifier::submitted($excuse);

        return response()->json([
            'message' => __('messages.excuse.created'),
            'data' => new AbsenceExcuseResource($excuse->load('student')),
        ], 201);
    }

    public function show(AbsenceExcuse $excuse): AbsenceExcuseResource
    {
        $this->authorize('view', $excuse);

        return new AbsenceExcuseResource($excuse->load(['student', 'reviewer']));
    }

    /**
     * Accept or reject. An accepted excuse is what turns a stored "absent" into
     * the derived "excused absence" everywhere it is counted.
     */
    public function review(ReviewExcuseRequest $request, AbsenceExcuse $excuse): JsonResponse
    {
        $this->authorize('review', $excuse);

        if ($excuse->status !== ExcuseStatus::Pending) {
            return response()->json(['message' => __('messages.excuse.already_reviewed')], 422);
        }

        $excuse->update([
            ...$request->validated(),
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        // The key `excuse_reviewed` has been in the catalog since the start,
        // but nothing ever sent it: a parent had to reopen the app to learn
        // whether the absence was cleared.
        ExcuseNotifier::reviewed($excuse->refresh());

        return response()->json([
            'message' => __('messages.excuse.reviewed'),
            'data' => new AbsenceExcuseResource($excuse->fresh(['student', 'reviewer'])),
        ]);
    }

    public function destroy(AbsenceExcuse $excuse): JsonResponse
    {
        $this->authorize('delete', $excuse);

        $excuse->delete();

        return response()->json(['message' => __('messages.excuse.deleted')]);
    }
}
