<?php

namespace App\Http\Controllers\Api;

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Enums\GateScanResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\StoreGateScanRequest;
use App\Http\Requests\Attendance\StoreStudentCardRequest;
use App\Http\Resources\GateScanResource;
use App\Http\Resources\StudentCardResource;
use App\Models\AttendanceRecord;
use App\Models\GateScan;
use App\Models\Student;
use App\Models\StudentCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class GateAttendanceController extends Controller
{
    /** Link an NFC card to a student; any previous card is revoked. */
    public function storeCard(StoreStudentCardRequest $request, Student $student): JsonResponse
    {
        $this->authorize('update', $student);

        $card = $student->cards()->create($request->validated());

        return response()->json([
            'message' => __('messages.card.linked'),
            'data' => new StudentCardResource($card),
        ], 201);
    }

    public function cards(Student $student): AnonymousResourceCollection
    {
        $this->authorize('view', $student);

        return StudentCardResource::collection(
            $student->cards()->orderByDesc('issued_at')->get(),
        );
    }

    public function revokeCard(StudentCard $card): JsonResponse
    {
        $this->authorize('delete', $card);

        $card->revoke();

        return response()->json([
            'message' => __('messages.card.revoked'),
            'data' => new StudentCardResource($card->fresh()),
        ]);
    }

    /**
     * One scan from the reader panel. Every outcome is logged, including cards
     * that match nothing (decision 16-c), and a matched card writes a daily
     * present record with source=gate.
     */
    public function scan(StoreGateScanRequest $request): JsonResponse
    {
        $this->authorize('create', GateScan::class);

        $schoolId = $request->user()->school_id;
        $uid = $request->string('nfc_uid')->toString();
        $scannedAt = $request->date('scanned_at') ?? now();
        $date = ($request->date('date') ?? $scannedAt)->toDateString();

        $card = StudentCard::query()
            ->where('nfc_uid', $uid)
            ->whereHas('student', fn ($q) => $q->where('school_id', $schoolId))
            ->with('student')
            ->first();

        [$result, $student] = $this->resolve($card, $date);

        $scan = DB::transaction(function () use ($schoolId, $uid, $student, $scannedAt, $result, $request, $date) {
            if ($result === GateScanResult::Accepted) {
                $enrollment = $student->currentEnrollment;

                AttendanceRecord::updateOrCreate(
                    ['student_id' => $student->id, 'date' => $date],
                    [
                        'section_id' => $enrollment->section_id,
                        'status' => AttendanceStatus::Present,
                        'source' => AttendanceSource::Gate,
                        'recorded_by' => $request->user()->id,
                        'recorded_at' => $scannedAt,
                    ],
                );
            }

            return GateScan::create([
                'school_id' => $schoolId,
                'nfc_uid' => $uid,
                'student_id' => $student?->id,
                'scanned_at' => $scannedAt,
                'result' => $result,
                'scanned_by' => $request->user()->id,
            ]);
        });

        return response()->json([
            'message' => $result->label(),
            'data' => new GateScanResource($scan->load('student.currentEnrollment.section.grade')),
        ], $result === GateScanResult::Accepted ? 201 : 200);
    }

    /** The scan panel's running list for the day. */
    public function scans(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', GateScan::class);

        $scans = GateScan::query()
            ->ofSchool($request->user()->school_id)
            ->when(
                $request->filled('date'),
                fn ($q) => $q->whereDate('scanned_at', $request->date('date')),
                fn ($q) => $q->whereDate('scanned_at', now()),
            )
            ->with('student')
            ->orderByDesc('scanned_at')
            ->paginate($request->integer('per_page', 50))
            ->withQueryString();

        return GateScanResource::collection($scans);
    }

    /**
     * @return array{0: GateScanResult, 1: ?Student}
     */
    private function resolve(?StudentCard $card, string $date): array
    {
        if (! $card) {
            return [GateScanResult::UnknownCard, null];
        }

        if (! $card->is_active) {
            return [GateScanResult::RevokedCard, $card->student];
        }

        $student = $card->student;

        // Without a current enrollment there is no section to file the record under.
        if (! $student->currentEnrollment) {
            return [GateScanResult::UnknownCard, $student];
        }

        $already = $student->attendanceRecords()->whereDate('date', $date)->exists();

        return [$already ? GateScanResult::Duplicate : GateScanResult::Accepted, $student];
    }
}
