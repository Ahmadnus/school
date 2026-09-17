<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\StoreStudentRequest;
use App\Http\Requests\Student\UpdateStudentRequest;
use App\Http\Resources\StudentResource;
use App\Models\FeeType;
use App\Models\Section;
use App\Support\Money;
use App\Services\FeePlanBuilder;
use App\Models\Student;
use App\Models\StudentEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class StudentController extends Controller
{
    /**
     * Student list with the "all / grade / section" filter chips.
     * Grade and section filters both run through the currentYear scope,
     * because a student's section only exists inside an academic year.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Student::class);

        $students = Student::query()
            ->ofSchool($request->user()->school_id)
            // A guardian sees their own children only, never the school roll.
            ->when(
                $request->user()->role->isGuardian(),
                fn ($q) => $q->whereHas(
                    'guardians',
                    fn ($g) => $g->where('guardians.user_id', $request->user()->id),
                ),
            )
            ->when($request->filled('section_id'), fn ($q) => $q->inSection($request->integer('section_id')))
            ->when(
                $request->filled('grade_id') && ! $request->filled('section_id'),
                fn ($q) => $q->inGrade($request->integer('grade_id')),
            )
            ->when($request->boolean('enrolled_only'), fn ($q) => $q->currentYear())
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($sub) => $sub
                    ->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('student_number', 'like', $term)
                    ->orWhere('external_id', 'like', $term));
            })
            ->with('currentEnrollment.section.grade')
            ->orderBy('first_name')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return StudentResource::collection($students);
    }

    public function store(StoreStudentRequest $request): JsonResponse
    {
        $this->authorize('create', Student::class);

        $data = $request->safe();

        $student = DB::transaction(function () use ($request, $data) {
            $student = Student::create([
                ...$data->except(['section_id', 'scope', 'enrolled_at', 'transport_subscribed']),
                'school_id' => $request->user()->school_id,
            ]);

            // The grade/section block of the create form is optional; when it is
            // filled the student is enrolled into the section's own academic year.
            if ($sectionId = $data['section_id'] ?? null) {
                $section = Section::findOrFail($sectionId);

                $enrollment = $student->enrollments()->create([
                    'section_id' => $section->id,
                    'academic_year_id' => $section->academic_year_id,
                    'scope' => $data['scope'],
                    'enrolled_at' => $data['enrolled_at'],
                    'transport_subscribed' => $request->boolean('transport_subscribed'),
                ]);

                self::attachPlan($request, $data, $student, $section, $enrollment);
            }

            return $student;
        });

        return response()->json([
            'message' => __('messages.student.created'),
            'data' => new StudentResource($student->load('currentEnrollment.section.grade')),
        ], 201);
    }

    /**
     * خطة الرسوم لحظة التسجيل.
     *
     * إنشاؤها هنا لا في شاشة ثانية مقصود: الطالب المسجّل بلا خطة لا يظهر
     * في أي تقرير مالي، فيُنسى حتى يحلّ موعد التحصيل. وكلّه داخل معاملة
     * `store` نفسها، فإمّا طالب بخطته أو لا طالب — لا نصف تسجيل.
     *
     * وضعان:
     *  - `full`     — المبلغ والأقساط من نوع الرسوم الافتراضي للصف.
     *  - `subjects` — مواد مختارة تُحفظ، والمبلغ يُكتب يدويّاً.
     */
    private static function attachPlan(
        Request $request,
        $data,
        Student $student,
        Section $section,
        StudentEnrollment $enrollment,
    ): void {
        $mode = $data['plan_mode'] ?? 'none';

        if ($mode === 'none') {
            return;
        }

        if ($mode === 'subjects') {
            $enrollment->subjects()->sync($data['subject_ids'] ?? []);
        }

        // في الخطة الكاملة يحمل النوع جدول أقساطه معه؛ في المواد المختارة
        // لا نوع لها والمبلغ يأتي من المستخدم.
        $type = $mode === 'full'
            ? FeeType::query()
                ->with('installments')
                ->ofSchool($student->school_id)
                ->where('grade_id', $section->grade_id)
                ->where('is_default', true)
                ->first()
            : null;

        $total = isset($data['plan_total_amount'])
            ? Money::fromDecimal($data['plan_total_amount'])
            : ($type?->totalAmount() ?? Money::zero());

        // مبلغ صفري يعني أن المدرسة لم تضبط نوعاً افتراضيّاً للصف بعد؛
        // خطة بصفر ليست خطة، وإنشاؤها يعني رقماً كاذباً في التقارير.
        if ($total->isZero()) {
            return;
        }

        $discount = Money::fromDecimal($data['plan_discount_amount'] ?? 0);

        if ($discount->greaterThan($total)) {
            $discount = Money::zero();
        }

        FeePlanBuilder::create(
            studentId: $student->id,
            academicYearId: $section->academic_year_id,
            type: $type,
            totalAmount: $total,
            discountAmount: $discount,
            installments: null,
            discountReason: $data['plan_discount_reason'] ?? null,
            notes: null,
            paymentReminders: true,
        );
    }

    public function show(Student $student): StudentResource
    {
        $this->authorize('view', $student);

        return new StudentResource($student->load([
            'currentEnrollment.section.grade',
            'currentEnrollment.subjects',
            'enrollments.section.grade',
            'enrollments.academicYear',
            'enrollments.subjects',
            'guardians',
        ]));
    }

    public function update(UpdateStudentRequest $request, Student $student): JsonResponse
    {
        $this->authorize('update', $student);

        $student->update($request->validated());

        return response()->json([
            'message' => __('messages.student.updated'),
            'data' => new StudentResource($student->fresh('currentEnrollment.section.grade')),
        ]);
    }

    public function destroy(Student $student): JsonResponse
    {
        $this->authorize('delete', $student);

        $student->delete();

        return response()->json(['message' => __('messages.student.deleted')]);
    }
}
