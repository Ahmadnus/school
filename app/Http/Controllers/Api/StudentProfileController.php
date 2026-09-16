<?php

namespace App\Http\Controllers\Api;

use App\Enums\FeePlanStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\StudentEnrollmentResource;
use App\Http\Resources\StudentResource;
use App\Http\Resources\TermResource;
use App\Models\Student;
use App\Models\Term;
use App\Enums\UserRole;
use App\Services\StudentAttendanceSummary;
use App\Services\StudentSignals;
use App\Services\StudentSubjects;
use App\Services\StudentTimeline;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The student profile — one entry point that tells the client what the
 * caller is allowed to open, plus the per-section endpoints behind the
 * profile buttons. Every section re-checks the policy on its own, so the
 * `permissions` block is a convenience for the UI, never the gate.
 */
class StudentProfileController extends Controller
{
    /** Header + summary counters + what this caller may open. */
    public function show(Request $request, Student $student): JsonResponse
    {
        $this->authorize('view', $student);

        $user = $request->user();
        $student->load([
            'currentEnrollment.section.grade',
            'currentEnrollment.academicYear',
            'guardians',
        ]);

        $permissions = [
            'update' => $user->can('update', $student),
            'delete' => $user->can('delete', $student),
            'enroll' => $user->can('enroll', $student),
            'view_academic' => $user->can('viewAcademic', $student),
            'view_notes' => $user->can('viewNotes', $student),
            'manage_notes' => $user->can('manageNotes', $student),
            'view_behavior' => $user->can('viewBehavior', $student),
            'manage_behavior' => $user->can('manageBehavior', $student),
            'view_fees' => $user->can('viewFees', $student),
            'manage_fees' => $user->can('create', \App\Models\FeePlan::class)
                && $user->school_id === $student->school_id,
            'review_excuses' => $user->role->isAdministrative(),
            'submit_excuse' => $user->can('viewAcademic', $student),
        ];

        $yearId = $student->currentEnrollment?->academic_year_id;

        $attendance = $permissions['view_academic']
            ? StudentAttendanceSummary::for($student, function ($q) use ($yearId) {
                if ($yearId) {
                    $q->whereHas('section', fn ($s) => $s->where('academic_year_id', $yearId));
                }
            })
            : null;

        if ($attendance !== null) {
            // The school's own limit, so the header can flag repeated absences.
            $threshold = (int) ($student->school->absence_warning_threshold ?? 0);
            $attendance['warning_threshold'] = $threshold > 0 ? $threshold : null;
            $attendance['over_threshold'] = $threshold > 0 && $attendance['unexcused'] >= $threshold;
        }

        $fees = null;
        if ($permissions['view_fees']) {
            $plans = $student->feePlans()
                ->where('status', FeePlanStatus::Active)
                ->with(['activePayments', 'installments.activeAllocations'])
                ->get();

            $net = \App\Support\Money::sum($plans->map(fn ($p) => $p->netAmount()));
            $paid = \App\Support\Money::sum($plans->map(fn ($p) => $p->paidAmount()));
            $overdue = $plans->flatMap(fn ($p) => $p->installments)
                ->filter(fn ($i) => $i->isOverdue())
                ->count();

            $fees = [
                'plans_count' => $plans->count(),
                'net_amount' => $net->toDecimal(),
                'paid_amount' => $paid->toDecimal(),
                'remaining_amount' => $net->minus($paid)->clampToZero()->toDecimal(),
                'overdue_installments' => $overdue,
            ];
        }

        return response()->json([
            'data' => [
                'student' => new StudentResource($student),
                'permissions' => $permissions,
                'counts' => [
                    'guardians' => $student->guardians->count(),
                    'enrollments' => $student->enrollments()->count(),
                    'notes' => $permissions['view_notes'] ? $student->notes()->count() : null,
                    'behavior' => $permissions['view_behavior']
                        ? ($user->role->isGuardian()
                            ? $student->behaviorRecords()->sharedWithGuardian()->count()
                            : $student->behaviorRecords()->count())
                        : null,
                    'open_behavior' => $permissions['manage_behavior']
                        ? $student->behaviorRecords()->where('status', 'open')->count()
                        : null,
                ],
                'attendance' => $attendance,
                'fees' => $fees,
                // Neutral attention signals; the fee signal only for those who see money.
                'signals' => $permissions['view_academic']
                    ? StudentSignals::forStudent($student, $student->school, includeFees: $permissions['view_fees'])
                    : null,
            ],
        ]);
    }

    /** The attention signals alone (the profile hub embeds them too). */
    public function signals(Request $request, Student $student): JsonResponse
    {
        $this->authorize('viewAcademic', $student);

        return response()->json([
            'data' => StudentSignals::forStudent(
                $student,
                $student->school,
                includeFees: $request->user()->can('viewFees', $student),
            ),
        ]);
    }

    /**
     * Students of the current year who carry at least one signal — staff only.
     * Grouped candidate queries first, then the full rule set per candidate.
     */
    public function attention(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorize('viewAny', Student::class);
        abort_if(
            $user->role->isGuardian() || $user->role === UserRole::Driver,
            403,
            __('messages.unauthorized'),
        );

        $includeFees = $user->role->isAdministrative();
        $school = $user->school;

        $base = Student::query()
            ->ofSchool($user->school_id)
            ->currentYear()
            ->when($request->filled('section_id'), fn ($q) => $q->inSection($request->integer('section_id')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($sub) => $sub
                    ->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('student_number', 'like', $term));
            });

        $candidateIds = StudentSignals::candidates($school, $base->pluck('id'), $includeFees);

        $students = Student::query()
            ->whereIn('id', $candidateIds)
            ->with('currentEnrollment.section.grade')
            ->orderBy('first_name')
            ->get();

        $wanted = $request->filled('level') ? $request->string('level')->toString() : null;

        $rows = $students->map(function (Student $student) use ($school, $includeFees) {
            $signals = StudentSignals::forStudent($student, $school, includeFees: $includeFees);

            return $signals['level'] === StudentSignals::LEVEL_NONE
                ? null
                : ['student' => $student, 'signals' => $signals];
        })->filter()
            ->when($wanted, fn ($c) => $c->filter(fn ($r) => $r['signals']['level'] === $wanted))
            // Attention before follow-up, then by name.
            ->sortBy([
                fn ($a, $b) => strcmp($a['signals']['level'], $b['signals']['level']),
                fn ($a, $b) => strcmp($a['student']->full_name, $b['student']->full_name),
            ])
            ->values();

        $perPage = $request->integer('per_page', 20);
        $page = max(1, $request->integer('page', 1));
        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return response()->json([
            'data' => collect($paginator->items())->map(fn (array $r) => [
                ...(new StudentResource($r["student"]))->resolve($request),
                'signals' => $r['signals'],
            ])->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * The student's journey, newest first. Default window: the current
     * academic year; `types[]` narrows the sources.
     */
    public function timeline(Request $request, Student $student): JsonResponse
    {
        $this->authorize('view', $student);

        $year = $student->currentEnrollment?->academicYear;
        $from = $request->filled('from')
            ? $request->date('from')->toDateString()
            : ($year?->start_date?->toDateString() ?? now()->subYear()->toDateString());
        $to = $request->filled('to')
            ? $request->date('to')->toDateString()
            : now()->addYear()->toDateString();

        $types = array_values(array_filter((array) $request->input('types', []), 'is_string'));

        $events = StudentTimeline::for($student, $request->user(), $from, $to, $types);

        $perPage = min(100, max(1, $request->integer('per_page', 30)));
        $page = max(1, $request->integer('page', 1));

        return response()->json([
            'data' => $events->forPage($page, $perPage)->values(),
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($events->count() / $perPage)),
                'per_page' => $perPage,
                'total' => $events->count(),
                'from' => $from,
                'to' => $to,
            ],
        ]);
    }

    /** The subjects of the current (or requested) term, with teacher and running score. */
    public function subjects(Request $request, Student $student): JsonResponse
    {
        $this->authorize('viewAcademic', $student);

        $termId = $request->filled('term_id') ? $request->integer('term_id') : null;
        $result = StudentSubjects::for($student, $termId);

        $terms = $result['enrollment']
            ? Term::query()
                ->where('academic_year_id', $result['enrollment']->academic_year_id)
                ->orderBy('start_date')
                ->get()
            : collect();

        return response()->json([
            'data' => [
                'enrollment' => $result['enrollment']
                    ? new StudentEnrollmentResource($result['enrollment'])
                    : null,
                'term' => $result['term'] ? new TermResource($result['term']) : null,
                'terms' => TermResource::collection($terms),
                'subjects' => $result['subjects'],
            ],
        ]);
    }

    /**
     * Attendance counters and rates, filterable the same way as the list:
     * from / to / academic_year_id / term_id / section_id.
     */
    public function attendanceSummary(Request $request, Student $student): JsonResponse
    {
        $this->authorize('viewAcademic', $student);

        $summary = StudentAttendanceSummary::for($student, function ($q) use ($request) {
            $q->when($request->filled('from'), fn ($q) => $q->whereDate('date', '>=', $request->date('from')))
                ->when($request->filled('to'), fn ($q) => $q->whereDate('date', '<=', $request->date('to')))
                ->when($request->filled('section_id'), fn ($q) => $q->where('section_id', $request->integer('section_id')))
                ->when($request->filled('academic_year_id'), fn ($q) => $q->whereHas(
                    'section',
                    fn ($s) => $s->where('academic_year_id', $request->integer('academic_year_id')),
                ))
                ->when($request->filled('term_id'), function ($q) use ($request) {
                    $term = Term::find($request->integer('term_id'));
                    if ($term) {
                        $q->whereDate('date', '>=', $term->start_date)
                            ->whereDate('date', '<=', $term->end_date);
                    }
                });
        });

        return response()->json(['data' => $summary]);
    }

    /** Every phone number that matters for this student, ready to dial. */
    public function contacts(Request $request, Student $student): JsonResponse
    {
        $this->authorize('view', $student);

        $contacts = [];

        if ($student->phone || $student->email) {
            $contacts[] = [
                'kind' => 'student',
                'name' => $student->full_name,
                'relation_label' => __('contact_kinds.student'),
                'phone' => $student->phone,
                'email' => $student->email,
                'is_primary' => false,
            ];
        }

        foreach ($student->guardians()->orderByPivot('is_primary', 'desc')->orderBy('name')->get() as $guardian) {
            $contacts[] = [
                'kind' => 'guardian',
                'guardian_id' => $guardian->id,
                'name' => $guardian->name,
                'relation' => $guardian->pivot->relation,
                'relation_label' => __('guardian_relations.'.$guardian->pivot->relation),
                'phone' => $guardian->phone,
                'email' => $guardian->email,
                'is_primary' => (bool) $guardian->pivot->is_primary,
            ];
        }

        if ($student->emergency_contact_phone || $student->emergency_contact_name) {
            $contacts[] = [
                'kind' => 'emergency',
                'name' => $student->emergency_contact_name,
                'relation_label' => $student->emergency_contact_relation ?: __('contact_kinds.emergency'),
                'phone' => $student->emergency_contact_phone,
                'email' => null,
                'is_primary' => false,
            ];
        }

        return response()->json(['data' => $contacts]);
    }
}
