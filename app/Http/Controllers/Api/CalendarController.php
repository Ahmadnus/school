<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\CalendarFeed;
use App\Services\PostAudience;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The calendar is a union view, not a table (decision 10-a) — which is why
 * there is no "add event" endpoint here.
 */
class CalendarController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isGuardian = $user->role->isGuardian();

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'section_id' => ['nullable', 'integer'],
            // A guardian always reads one child's calendar: without it the feed
            // would carry the whole school's instalments under other names.
            'student_id' => [$isGuardian ? 'required' : 'nullable', 'integer'],
        ]);

        $from = isset($data['from']) ? $request->date('from')->toDateString() : now()->toDateString();
        $to = isset($data['to']) ? $request->date('to')->toDateString() : $from;

        $sectionId = $data['section_id'] ?? null;
        $postIds = null;

        if ($isGuardian) {
            $child = Student::query()
                ->ofSchool($user->school_id)
                ->whereHas('guardians', fn ($g) => $g->where('guardians.user_id', $user->id))
                ->with('currentEnrollment')
                ->find($data['student_id']);
            abort_unless($child, 404);

            // The child's own section and posts — never a section they asked for.
            $sectionId = $child->currentEnrollment?->section_id;
            $postIds = PostAudience::postIdsForGuardian($user, $child->id);
        }

        $events = CalendarFeed::between(
            schoolId: $user->school_id,
            from: $from,
            to: $to,
            sectionId: $sectionId,
            studentId: $data['student_id'] ?? null,
            postIds: $postIds,
        );

        return response()->json([
            'data' => [
                'from' => $from,
                'to' => $to,
                'events' => $events,
            ],
        ]);
    }
}
