<?php

namespace App\Http\Controllers\Api;

use App\Enums\NotificationApp;
use App\Http\Controllers\Controller;
use App\Http\Requests\StudentProfile\StoreBehaviorRecordRequest;
use App\Http\Requests\StudentProfile\UpdateBehaviorRecordRequest;
use App\Http\Resources\BehaviorRecordResource;
use App\Models\BehaviorRecord;
use App\Models\Student;
use App\Services\NotificationGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The behaviour log of a student. Staff see everything; a guardian sees only
 * the records the school flagged `visible_to_guardian`, and only for their
 * own child (StudentPolicy::viewBehavior).
 */
class BehaviorRecordController extends Controller
{
    public function index(Request $request, Student $student): AnonymousResourceCollection
    {
        $this->authorize('viewBehavior', $student);

        $records = $student->behaviorRecords()
            ->with('recorder')
            ->when($request->user()->role->isGuardian(), fn ($q) => $q->sharedWithGuardian())
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('occurred_on', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('occurred_on', '<=', $request->date('to')))
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return BehaviorRecordResource::collection($records);
    }

    public function store(StoreBehaviorRecordRequest $request, Student $student): JsonResponse
    {
        $this->authorize('manageBehavior', $student);

        $record = $student->behaviorRecords()->create([
            ...$request->validated(),
            'recorded_by' => $request->user()->id,
        ]);

        $this->notifyGuardians($record);

        return response()->json([
            'message' => __('messages.behavior.created'),
            'data' => new BehaviorRecordResource($record->load('recorder')),
        ], 201);
    }

    public function update(UpdateBehaviorRecordRequest $request, BehaviorRecord $record): JsonResponse
    {
        $this->authorize('update', $record);

        $wasShared = $record->visible_to_guardian;
        $record->update($request->validated());

        if (! $wasShared && $record->visible_to_guardian) {
            $this->notifyGuardians($record);
        }

        return response()->json([
            'message' => __('messages.behavior.updated'),
            'data' => new BehaviorRecordResource($record->fresh('recorder')),
        ]);
    }

    public function destroy(BehaviorRecord $record): JsonResponse
    {
        $this->authorize('delete', $record);

        $record->delete();

        return response()->json(['message' => __('messages.behavior.deleted')]);
    }

    /** A record shared with the family reaches every guardian who has the app. */
    private function notifyGuardians(BehaviorRecord $record): void
    {
        if (! $record->visible_to_guardian) {
            return;
        }

        $record->loadMissing('student.guardians.user');

        foreach ($record->student->guardians as $guardian) {
            if (! $guardian->user) {
                continue;
            }

            NotificationGate::notify(
                $guardian->user,
                'behavior_record',
                $record->type->label().' — '.$record->student->full_name,
                $record->title,
                $record->id,
                NotificationApp::Guardian,
            );
        }
    }
}
