<?php

namespace App\Http\Controllers\Api;

use App\Enums\Gender;
use App\Enums\GuardianRelation;
use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\AdmissionApplication;
use App\Models\Guardian;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Services\EnrollmentFeePlanner;
use App\Support\PhoneLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * القبول والتسجيل — للإدارة فقط وضمن مدرسة المستخدم.
 *
 * لا شيء هنا يرسل رسالة لأحد: التقديم والمقابلة والقرار حالات داخلية. الطالب
 * الحقيقي لا يُنشأ إلا في `enroll`، وبعد قبول صريح، ومعه وليّ أمره وتسجيله في
 * الشعبة وخطة الرسوم كما في أي مسار تسجيل آخر.
 */
class AdmissionController extends Controller
{
    private function schoolId(Request $request): int
    {
        abort_unless($request->user()->role->isAdministrative(), 403);

        return $request->user()->school_id;
    }

    private function find(Request $request, int $id): AdmissionApplication
    {
        return AdmissionApplication::where('school_id', $this->schoolId($request))->findOrFail($id);
    }

    public function index(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId($request);
        $data = $request->validate([
            'status' => ['nullable', Rule::in(AdmissionApplication::STATUSES)],
            'q' => ['nullable', 'string', 'max:80'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = AdmissionApplication::query()
            ->where('school_id', $schoolId)
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($data['q'] ?? null, fn ($q, $v) => $q->where(function ($w) use ($v) {
                $w->where('first_name', 'like', "%{$v}%")
                    ->orWhere('last_name', 'like', "%{$v}%")
                    ->orWhere('guardian_phone', 'like', "%{$v}%");
            }))
            ->with('grade:id,name')
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 30);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($a) => $this->present($a))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId($request);
        $counts = AdmissionApplication::where('school_id', $schoolId)
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return response()->json(['data' => collect(AdmissionApplication::STATUSES)
            ->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)])]);
    }

    public function store(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId($request);
        $data = $this->validated($request, $schoolId);

        $application = AdmissionApplication::create([
            ...$data,
            'school_id' => $schoolId,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->present($application->load('grade:id,name'))], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $application = $this->find($request, $id);
        abort_if($application->status === 'enrolled', 422, __('admissions.locked'));

        $application->update($this->validated($request, $application->school_id));

        return response()->json(['data' => $this->present($application->load('grade:id,name'))]);
    }

    public function setStatus(Request $request, int $id): JsonResponse
    {
        $application = $this->find($request, $id);
        $data = $request->validate([
            'status' => ['required', Rule::in(AdmissionApplication::STATUSES)],
            'note' => ['nullable', 'string', 'max:500'],
            'interview_at' => ['nullable', 'date'],
        ]);

        abort_unless($application->canMoveTo($data['status']), 422, __('admissions.bad_transition'));

        $application->forceFill([
            'status' => $data['status'],
            'decision_note' => $data['note'] ?? $application->decision_note,
            'interview_at' => $data['status'] === 'interview'
                ? ($data['interview_at'] ?? $application->interview_at)
                : $application->interview_at,
            'decided_by' => in_array($data['status'], ['accepted', 'rejected'], true)
                ? $request->user()->id : $application->decided_by,
            'decided_at' => in_array($data['status'], ['accepted', 'rejected'], true)
                ? now() : $application->decided_at,
        ])->save();

        return response()->json(['data' => $this->present($application->load('grade:id,name'))]);
    }

    /**
     * التسجيل: طالب جديد + وليّ أمر (يُستعمل الموجود بالهاتف) + تسجيل في الشعبة +
     * خطة رسوم الصف. من «مقبول» فقط، مرّة واحدة، وبلا طالب مكرّر إلا بـ force.
     */
    public function enroll(Request $request, int $id): JsonResponse
    {
        $application = $this->find($request, $id);
        $schoolId = $application->school_id;

        $data = $request->validate([
            'section_id' => ['required', 'integer'],
            'relation' => ['nullable', Rule::enum(GuardianRelation::class)],
            'force' => ['nullable', 'boolean'],
        ]);

        abort_unless($application->status === 'accepted', 422, __('admissions.must_be_accepted'));

        $section = Section::query()
            ->whereHas('grade', fn ($q) => $q->where('school_id', $schoolId))
            ->findOrFail($data['section_id']);
        $year = AcademicYear::currentFor($schoolId);
        abort_if($year === null, 422, __('admissions.no_year'));
        abort_unless($section->academic_year_id === $year->id, 422, __('admissions.section_not_current'));

        if (! ($data['force'] ?? false)) {
            $duplicate = Student::where('school_id', $schoolId)
                ->where('first_name', $application->first_name)
                ->where('last_name', $application->last_name)
                ->when($application->birth_date, fn ($q) => $q->whereDate('birth_date', $application->birth_date))
                ->exists();
            abort_if($duplicate, 409, __('admissions.duplicate'));
        }

        $student = DB::transaction(function () use ($application, $schoolId, $section, $year, $data) {
            $student = Student::create([
                'school_id' => $schoolId,
                'first_name' => $application->first_name,
                'last_name' => $application->last_name,
                'birth_date' => $application->birth_date,
                'gender' => $application->gender,
            ]);

            $enrollment = StudentEnrollment::create([
                'student_id' => $student->id,
                'section_id' => $section->id,
                'academic_year_id' => $year->id,
                'enrolled_at' => now()->toDateString(),
            ]);

            if (filled($application->guardian_phone)) {
                $guardian = Guardian::where('school_id', $schoolId)
                    ->whereIn('phone', PhoneLookup::candidates($application->guardian_phone))->first()
                    ?? Guardian::create([
                        'school_id' => $schoolId,
                        'name' => $application->guardian_name ?: $application->guardian_phone,
                        'phone' => $application->guardian_phone,
                    ]);

                $student->guardianLinks()->create([
                    'guardian_id' => $guardian->id,
                    'relation' => $data['relation'] ?? GuardianRelation::Father->value,
                    'is_primary' => true,
                ]);
            }

            EnrollmentFeePlanner::ensureFor($enrollment);

            $application->forceFill(['status' => 'enrolled', 'student_id' => $student->id])->save();

            return $student;
        });

        return response()->json(['data' => [
            ...$this->present($application->load('grade:id,name')),
            'student_id' => $student->id,
        ]]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, int $schoolId): array
    {
        return $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'desired_grade_id' => ['nullable', Rule::exists('grades', 'id')->where('school_id', $schoolId)],
            'guardian_name' => ['nullable', 'string', 'max:120'],
            'guardian_phone' => ['nullable', 'string', 'max:30'],
            'previous_school' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(AdmissionApplication $a): array
    {
        return [
            'id' => $a->id,
            'first_name' => $a->first_name,
            'last_name' => $a->last_name,
            'full_name' => $a->fullName(),
            'birth_date' => $a->birth_date?->toDateString(),
            'gender' => $a->gender,
            'desired_grade_id' => $a->desired_grade_id,
            'grade' => $a->grade?->name,
            'guardian_name' => $a->guardian_name,
            'guardian_phone' => $a->guardian_phone,
            'previous_school' => $a->previous_school,
            'notes' => $a->notes,
            'status' => $a->status,
            'interview_at' => $a->interview_at?->toIso8601String(),
            'decision_note' => $a->decision_note,
            'student_id' => $a->student_id,
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }
}
