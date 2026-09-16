<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\StoreAssessmentRequest;
use App\Http\Requests\Academic\UpdateAssessmentRequest;
use App\Http\Resources\AssessmentResource;
use App\Models\Assessment;
use App\Services\GradeNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AssessmentController extends Controller
{
    /** The assessments screen is always opened from one subject. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Assessment::class);

        $assessments = Assessment::query()
            ->ofSchool($request->user()->school_id)
            ->when($request->filled('subject_id'), fn ($q) => $q->where('subject_id', $request->integer('subject_id')))
            ->with(['type', 'subject'])
            ->withCount('scores')
            ->orderBy('name')
            ->get();

        return AssessmentResource::collection($assessments);
    }

    public function store(StoreAssessmentRequest $request): JsonResponse
    {
        $this->authorize('create', Assessment::class);

        $assessment = Assessment::create($request->validated());

        // الأهل يعرفون بالامتحان قبل موعده لا بعد نتيجته.
        GradeNotifier::created($assessment);

        return response()->json([
            'message' => __('messages.assessment.created'),
            'data' => new AssessmentResource($assessment->load(['type', 'subject'])),
        ], 201);
    }

    /**
     * نشر العلامات: هنا وحده تنطلق الإشعارات.
     *
     * الحفظ في شبكة الإدخال عمل قيد التنفيذ — يُحفظ نصف الصف ثم يُكمَل غداً
     * ثم تُصحَّح علامة. لو أشعر كل حفظ لوصل الأهل خبر ناقص ثم خبر يناقضه.
     */
    public function publish(Request $request, Assessment $assessment): JsonResponse
    {
        $this->authorize('score', $assessment);

        if ($assessment->isPublished()) {
            return response()->json(['message' => __('messages.assessment.already_published')], 422);
        }

        if (! $assessment->scores()->whereNotNull('score')->exists()) {
            return response()->json(['message' => __('messages.assessment.nothing_to_publish')], 422);
        }

        $assessment->forceFill([
            'published_at' => now(),
            'published_by' => $request->user()->id,
        ])->save();

        GradeNotifier::published($assessment);

        return response()->json([
            'message' => __('messages.assessment.published'),
            'data' => new AssessmentResource($assessment->load(['type', 'subject'])),
        ]);
    }

    public function show(Assessment $assessment): AssessmentResource
    {
        $this->authorize('view', $assessment);

        return new AssessmentResource($assessment->load(['type', 'subject.grade', 'subject.term']));
    }

    public function update(UpdateAssessmentRequest $request, Assessment $assessment): JsonResponse
    {
        $this->authorize('update', $assessment);

        $assessment->update($request->validated());

        return response()->json([
            'message' => __('messages.assessment.updated'),
            'data' => new AssessmentResource($assessment->fresh(['type', 'subject'])),
        ]);
    }

    public function destroy(Assessment $assessment): JsonResponse
    {
        $this->authorize('delete', $assessment);

        if ($assessment->scores()->whereNotNull('score')->exists()) {
            return response()->json(['message' => __('messages.assessment.has_scores')], 422);
        }

        $assessment->delete();

        return response()->json(['message' => __('messages.assessment.deleted')]);
    }
}
