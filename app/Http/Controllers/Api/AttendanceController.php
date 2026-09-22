<?php

namespace App\Http\Controllers\Api;

use App\Enums\AttendanceSessionStatus;
use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\StoreAttendanceRequest;
use App\Http\Resources\AttendanceRecordResource;
use App\Http\Resources\AttendanceSessionResource;
use App\Http\Resources\StudentResource;
use App\Models\AbsenceExcuse;
use App\Models\AttendanceRecord;
use App\Models\Section;
use App\Models\Student;
use App\Services\AttendanceNotifier;
use App\Services\AttendanceSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    /**
     * The roll-call sheet: the section roster for a day, each student's status
     * so far, the session state, and the four-state summary bar.
     */
    public function sheet(Request $request, Section $section): JsonResponse
    {
        $this->authorize('view', $section);

        $date = $request->date('date')?->toDateString() ?? now()->toDateString();

        $students = Student::query()
            ->inSection($section->id)
            ->with('currentEnrollment.section.grade')
            ->orderBy('first_name')
            ->get();

        $records = $section->attendanceRecords()
            ->whereDate('date', $date)
            ->whereIn('student_id', $students->pluck('id'))
            ->get();

        $byStudent = $records->keyBy('student_id');
        $excused = AttendanceSummary::excusedStudentDays($records);
        $session = $section->attendanceSessions()->whereDate('date', $date)->first();

        return response()->json([
            'data' => [
                'section_id' => $section->id,
                'date' => $date,
                'session' => $session ? new AttendanceSessionResource($session) : null,
                'total_students' => $students->count(),
                // الحاضرون = المسجَّلون في الشعبة ناقص من كُتب له سجلّ.
                // جمعُ الحالات من الصفوف وحدها كان سيُظهر «حاضر: صفر» بعد
                // أن صار الحضور لا يُكتب.
                'summary' => [
                    ...AttendanceSummary::for($records),
                    'present' => max(0, $students->count() - $records->count()),
                ],
                'rows' => $students->map(fn (Student $student) => [
                    'student' => new StudentResource($student),
                    'record' => $byStudent->has($student->id)
                        ? new AttendanceRecordResource($byStudent[$student->id])
                        : null,
                    'is_excused' => $excused->has(
                        AttendanceSummary::key($student->id, $date),
                    ),
                ])->values(),
            ],
        ]);
    }

    /**
     * Saves the sheet. Partial submission is allowed, so only the rows sent are
     * written; passing submit=true also closes the day.
     */
    public function store(StoreAttendanceRequest $request, Section $section): JsonResponse
    {
        $this->authorize('takeAttendance', $section);

        $date = $request->date('date')->toDateString();

        DB::transaction(function () use ($request, $section, $date) {
            foreach ($request->input('records') as $row) {
                // الحضور لا يُكتب: وجود الطالب في جلسةٍ مُسلَّمة بلا سجلّ
                // **هو** حضوره. تسجيله كان يعني صفّاً لكل طالب كل يوم —
                // أربعةً وخمسين ألف صفّ في السنة، خمسةٌ وتسعون بالمئة منها
                // بلا معلومة، ومثلها إشعارات تقول «ابنك حضر».
                //
                // والسجلّ القديم يُحذف عند التصحيح: من وُسم غائباً ثم صحّحه
                // المعلّم يجب أن يزول وسمُه، لا أن يبقى وتُكتب فوقه حالة.
                if ($row['status'] === AttendanceStatus::Present->value) {
                    AttendanceRecord::query()
                        ->where('student_id', $row['student_id'])
                        ->where('date', $date)
                        ->delete();

                    continue;
                }

                AttendanceRecord::updateOrCreate(
                    ['student_id' => $row['student_id'], 'date' => $date],
                    [
                        'section_id' => $section->id,
                        'status' => $row['status'],
                        'source' => AttendanceSource::Manual,
                        'recorded_by' => $request->user()->id,
                        'recorded_at' => now(),
                    ],
                );
            }

            $session = $section->attendanceSessions()->firstOrNew(['date' => $date]);

            if ($request->boolean('submit')) {
                $session->fill([
                    'status' => AttendanceSessionStatus::Submitted,
                    'submitted_by' => $request->user()->id,
                    'submitted_at' => now(),
                ]);
            }

            $session->save();
        });

        // Families hear about absences/lateness only once the sheet is final.
        if ($request->boolean('submit')) {
            AttendanceNotifier::submitted($section, $date);
        }

        return response()->json([
            'message' => $request->boolean('submit')
                ? __('messages.attendance.submitted')
                : __('messages.attendance.saved'),
        ]);
    }

    /**
     * The attendance log: past records for a section, filterable by the status
     * chips including the derived "excused" one.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Section::class);

        $records = AttendanceRecord::query()
            ->ofSchool($request->user()->school_id)
            // A guardian only ever sees their own children's days.
            ->when(
                $request->user()->role->isGuardian(),
                fn ($q) => $q->whereHas('student.guardians', fn ($g) => $g->where('guardians.user_id', $request->user()->id)),
            )
            ->when($request->filled('section_id'), fn ($q) => $q->where('section_id', $request->integer('section_id')))
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('date', '<=', $request->date('to')))
            ->when($request->filled('status'), function ($q) use ($request) {
                $status = $request->string('status')->toString();

                // The chips offer four states; two of them are derived.
                match ($status) {
                    'excused' => $q->excused(),
                    'unexcused' => $q->unexcused(),
                    default => $q->where('status', $status),
                };
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->whereHas('student', fn ($s) => $s
                    ->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term));
            })
            ->with(['student', 'section.grade', 'recorder'])
            ->orderByDesc('date')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        $this->attachExcuses($records->getCollection());

        return AttendanceRecordResource::collection($records);
    }

    /**
     * Marks each absence on the page with the excuse that covers it (any
     * status), so the list can show "excused" and the reason without a
     * query per row. The derived flag is never stored (decision 4-a).
     *
     * @param  Collection<int, AttendanceRecord>  $records
     */
    private function attachExcuses($records): void
    {
        $absences = $records->where('status', AttendanceStatus::Absent);

        $excuses = $absences->isEmpty() ? collect() : AbsenceExcuse::query()
            ->whereIn('student_id', $absences->pluck('student_id')->unique())
            ->where('start_date', '<=', $absences->max('date')->toDateString())
            ->where('end_date', '>=', $absences->min('date')->toDateString())
            ->orderByRaw("case status when 'accepted' then 0 when 'pending' then 1 else 2 end")
            ->get();

        foreach ($records as $record) {
            $excuse = $record->status === AttendanceStatus::Absent
                ? $excuses->first(fn (AbsenceExcuse $e) => $e->student_id === $record->student_id
                    && $e->start_date <= $record->date
                    && $e->end_date >= $record->date)
                : null;

            $record->setAttribute('excuse_match', $excuse);
        }
    }
}
