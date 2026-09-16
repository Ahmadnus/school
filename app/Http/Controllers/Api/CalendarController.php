<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CalendarFeed;
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
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'section_id' => ['nullable', 'integer'],
            'student_id' => ['nullable', 'integer'],
        ]);

        $from = isset($data['from']) ? $request->date('from')->toDateString() : now()->toDateString();
        $to = isset($data['to']) ? $request->date('to')->toDateString() : $from;

        $events = CalendarFeed::between(
            schoolId: $request->user()->school_id,
            from: $from,
            to: $to,
            sectionId: $data['section_id'] ?? null,
            studentId: $data['student_id'] ?? null,
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
