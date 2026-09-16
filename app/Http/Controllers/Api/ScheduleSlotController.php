<?php

namespace App\Http\Controllers\Api;

use App\Enums\Weekday;
use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\StoreScheduleSlotRequest;
use App\Http\Requests\Academic\UpdateScheduleSlotRequest;
use App\Http\Resources\ScheduleSlotResource;
use App\Models\ScheduleSlot;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Term;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ScheduleSlotController extends Controller
{
    /** The schedule screen filters by section + day together. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ScheduleSlot::class);

        $slots = ScheduleSlot::query()
            ->ofSchool($request->user()->school_id)
            ->when($request->filled('section_id'), fn ($q) => $q->where('section_id', $request->integer('section_id')))
            ->when($request->filled('term_id'), fn ($q) => $q->where('term_id', $request->integer('term_id')))
            ->when($request->filled('staff_id'), fn ($q) => $q->where('staff_id', $request->integer('staff_id')))
            ->when($request->has('day_of_week'), fn ($q) => $q->where('day_of_week', $request->integer('day_of_week')))
            ->with(['subject', 'teacher', 'section.grade'])
            ->ordered()
            ->get();

        return ScheduleSlotResource::collection($slots);
    }

    public function store(StoreScheduleSlotRequest $request): JsonResponse
    {
        $this->authorize('create', ScheduleSlot::class);

        $data = $request->validated();

        if ($error = $this->consistencyError($data)) {
            return response()->json(['message' => $error], 422);
        }

        $slot = ScheduleSlot::create($data);

        return response()->json([
            'message' => __('messages.slot.created'),
            'data' => new ScheduleSlotResource($slot->load(['subject', 'teacher', 'section'])),
        ], 201);
    }

    public function update(UpdateScheduleSlotRequest $request, ScheduleSlot $slot): JsonResponse
    {
        $this->authorize('update', $slot);

        $data = [...$slot->only(['section_id', 'term_id', 'subject_id', 'staff_id']), ...$request->validated()];
        $data['day_of_week'] = $data['day_of_week'] ?? $slot->day_of_week->value;
        $data['starts_at'] = $data['starts_at'] ?? $slot->starts_at;
        $data['ends_at'] = $data['ends_at'] ?? $slot->ends_at;

        if ($error = $this->consistencyError($data, $slot->id)) {
            return response()->json(['message' => $error], 422);
        }

        $slot->update($request->validated());

        return response()->json([
            'message' => __('messages.slot.updated'),
            'data' => new ScheduleSlotResource($slot->fresh(['subject', 'teacher', 'section'])),
        ]);
    }

    public function destroy(ScheduleSlot $slot): JsonResponse
    {
        $this->authorize('delete', $slot);

        $slot->delete();

        return response()->json(['message' => __('messages.slot.deleted')]);
    }

    /** The day strip on the schedule screen, already translated. */
    public function days(): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                fn (Weekday $day) => ['value' => $day->value, 'label' => $day->label()],
                Weekday::schoolWeek(),
            ),
        ]);
    }

    /**
     * Rules that span several tables: the subject must belong to the section's
     * grade and term, and a teacher cannot be in two rooms at once.
     */
    private function consistencyError(array $data, ?int $ignoreSlotId = null): ?string
    {
        $section = Section::findOrFail($data['section_id']);
        $subject = Subject::findOrFail($data['subject_id']);
        $term = Term::findOrFail($data['term_id']);

        if ($subject->grade_id !== $section->grade_id) {
            return __('messages.slot.grade_mismatch');
        }

        if ($subject->term_id !== $term->id || $term->academic_year_id !== $section->academic_year_id) {
            return __('messages.slot.term_mismatch');
        }

        if (empty($data['staff_id'])) {
            return null;
        }

        $clash = ScheduleSlot::query()
            ->where('staff_id', $data['staff_id'])
            ->where('term_id', $term->id)
            ->where('day_of_week', $data['day_of_week'])
            ->when($ignoreSlotId, fn ($q) => $q->whereKeyNot($ignoreSlotId))
            ->where('starts_at', '<', $data['ends_at'])
            ->where('ends_at', '>', $data['starts_at'])
            ->exists();

        return $clash ? __('messages.slot.teacher_busy') : null;
    }
}
