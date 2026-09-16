<?php

namespace App\Http\Controllers\Api;

use App\Enums\ReportCardStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Report\StoreReportCardRequest;
use App\Http\Resources\ReportCardResource;
use App\Models\ReportCard;
use App\Models\Term;
use App\Services\ReportCardBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReportCardController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ReportCard::class);

        $cards = ReportCard::query()
            ->ofSchool($request->user()->school_id)
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->when($request->filled('term_id'), fn ($q) => $q->where('term_id', $request->integer('term_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->with(['student.currentEnrollment.section.grade', 'term'])
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return ReportCardResource::collection($cards);
    }

    public function store(StoreReportCardRequest $request): JsonResponse
    {
        $this->authorize('create', ReportCard::class);

        $term = Term::findOrFail($request->integer('term_id'));

        $card = ReportCard::create([
            ...$request->validated(),
            'academic_year_id' => $term->academic_year_id,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => __('messages.report_card.created'),
            'data' => new ReportCardResource($card->load(['student', 'term'])),
        ], 201);
    }

    /**
     * The preview screen the spec lists as missing (#4). A draft returns the
     * live sheet; a published card returns its frozen lines (decision 11-c).
     */
    public function show(ReportCard $reportCard): JsonResponse
    {
        $this->authorize('view', $reportCard);

        $reportCard->load(['student.currentEnrollment.section.grade', 'term', 'lines.subject']);

        $computed = $reportCard->isDraft() ? ReportCardBuilder::compute($reportCard) : null;

        return response()->json([
            'data' => [
                'card' => new ReportCardResource($reportCard),
                'is_draft' => $reportCard->isDraft(),
                // Draft: computed live. Published: whatever was frozen.
                'lines' => $computed['lines'] ?? $reportCard->lines->map(fn ($line) => [
                    'subject_id' => $line->subject_id,
                    'subject' => $line->subject?->name,
                    'score' => $line->score,
                    'grade_label' => $line->grade_label,
                ])->all(),
                'average' => $computed['average'] ?? $reportCard->average,
            ],
        ]);
    }

    public function update(Request $request, ReportCard $reportCard): JsonResponse
    {
        $this->authorize('update', $reportCard);

        if (! $reportCard->isDraft()) {
            return response()->json(['message' => __('messages.report_card.already_published')], 422);
        }

        $data = $request->validate([
            'supervisor_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $reportCard->update($data);

        return response()->json([
            'message' => __('messages.report_card.updated'),
            'data' => new ReportCardResource($reportCard->fresh()),
        ]);
    }

    /** Freezes the computed sheet into report_card_lines and publishes. */
    public function publish(ReportCard $reportCard): JsonResponse
    {
        $this->authorize('publish', $reportCard);

        if ($reportCard->status === ReportCardStatus::Published) {
            return response()->json(['message' => __('messages.report_card.already_published')], 422);
        }

        $card = ReportCardBuilder::publish($reportCard);

        return response()->json([
            'message' => __('messages.report_card.published'),
            'data' => new ReportCardResource($card->load(['student', 'term', 'lines.subject'])),
        ]);
    }

    public function destroy(ReportCard $reportCard): JsonResponse
    {
        $this->authorize('delete', $reportCard);

        $reportCard->delete();

        return response()->json(['message' => __('messages.report_card.deleted')]);
    }
}
