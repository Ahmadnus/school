<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fee\StoreFeePlanRequest;
use App\Http\Requests\Fee\UpdateFeePlanInstallmentsRequest;
use App\Http\Requests\Fee\UpdateFeePlanRequest;
use App\Http\Resources\FeePlanResource;
use App\Models\FeePlan;
use App\Models\FeeType;
use App\Models\Student;
use App\Services\FeePlanBuilder;
use App\Services\FeeStatement;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FeePlanController extends Controller
{
    /** شاشة الرسوم: بحث الطلاب وشرائح الحالة وفلاتر الخصم والتأخير. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', FeePlan::class);

        $plans = FeePlan::query()
            ->ofSchool($request->user()->school_id)
            ->when(
                $request->user()->role->isGuardian(),
                fn ($q) => $q->whereHas('student.guardians', fn ($g) => $g->where('guardians.user_id', $request->user()->id)),
            )
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->when(
                $request->filled('academic_year_id'),
                fn ($q) => $q->where('academic_year_id', $request->integer('academic_year_id')),
            )
            ->when(
                $request->filled('payment_state'),
                fn ($q) => $q->withPaymentState(PaymentState::from($request->string('payment_state')->toString())),
            )
            // فلتر «أصحاب الخصومات»: قائمة كل خطة عليها خصم، بسببه.
            ->when($request->has('has_discount'), fn ($q) => $q->hasDiscount($request->boolean('has_discount')))
            ->when($request->boolean('overdue'), fn ($q) => $q->overdue())
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->whereHas('student', fn ($s) => $s
                    ->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('student_number', 'like', $term));
            })
            ->with([
                'student.currentEnrollment.section.grade',
                'installments.activeAllocations',
                'activePayments',
            ])
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return FeePlanResource::collection($plans);
    }

    /**
     * تعيين الرسوم. الخطة تنسخ مبلغ النوع وأقساطه، ومن هنا فصاعداً هي مستقلة
     * عنه (قرار 2-أ). عند وجود خصم تُصغَّر الأقساط المنسوخة لتساوي الصافي.
     */
    public function store(StoreFeePlanRequest $request): JsonResponse
    {
        $this->authorize('create', FeePlan::class);

        $data = $request->validated();
        $type = isset($data['fee_type_id'])
            ? FeeType::with('installments')->find($data['fee_type_id'])
            : null;

        $total = isset($data['total_amount'])
            ? Money::fromDecimal($data['total_amount'])
            : ($type?->totalAmount() ?? Money::zero());

        $discount = Money::fromDecimal($data['discount_amount'] ?? 0);

        if ($discount->greaterThan($total)) {
            return response()->json(['message' => __('messages.fee_plan.discount_too_large')], 422);
        }

        $duplicate = FeePlan::query()
            ->where('student_id', $data['student_id'])
            ->where('academic_year_id', $data['academic_year_id'])
            ->where('fee_type_id', $type?->id)
            ->exists();

        if ($duplicate) {
            return response()->json(['message' => __('messages.fee_plan.duplicate')], 422);
        }

        $plan = FeePlanBuilder::create(
            studentId: $data['student_id'],
            academicYearId: $data['academic_year_id'],
            type: $type,
            totalAmount: $total,
            discountAmount: $discount,
            installments: $data['installments'] ?? null,
            discountReason: $data['discount_reason'] ?? null,
            notes: $data['notes'] ?? null,
            paymentReminders: $request->boolean('payment_reminders', true),
        );

        return response()->json([
            'message' => __('messages.fee_plan.created'),
            'data' => new FeePlanResource($plan->load(['student', 'installments.activeAllocations', 'activePayments'])),
        ], 201);
    }

    /** بطاقة رسوم الطالب، بجدول أقساطها الخاص. */
    public function show(FeePlan $feePlan): FeePlanResource
    {
        $this->authorize('view', $feePlan);

        return new FeePlanResource($feePlan->load([
            'student.currentEnrollment.section.grade',
            'academicYear',
            'installments.activeAllocations',
            'activePayments',
            'payments.allocations',
        ]));
    }

    public function update(UpdateFeePlanRequest $request, FeePlan $feePlan): JsonResponse
    {
        $this->authorize('update', $feePlan);

        $data = $request->validated();

        $attributes = array_filter([
            'total_minor' => isset($data['total_amount']) ? Money::fromDecimal($data['total_amount']) : null,
            'discount_minor' => array_key_exists('discount_amount', $data)
                ? Money::fromDecimal($data['discount_amount'] ?? 0)
                : null,
        ], fn ($value) => $value !== null);

        $attributes += array_intersect_key($data, array_flip([
            'discount_reason', 'notes', 'payment_reminders', 'status',
        ]));

        $feePlan->update($attributes);

        // تغيّر الصافي يكسر الثابت «مجموع الأقساط = الصافي»، فيُعاد ضبط
        // الجدول نسبياً بدل أن يبقى فرق بلا قسط يحمله.
        if (isset($attributes['total_minor']) || isset($attributes['discount_minor'])) {
            $feePlan->refresh();
            FeePlanBuilder::writeInstallments(
                $feePlan,
                $feePlan->installments->map(fn ($i) => [
                    'due_date' => $i->due_date->toDateString(),
                    'amount_minor' => $i->amount(),
                    'notes' => $i->notes,
                ])->all(),
                scaleToNet: true,
            );
        }

        return response()->json([
            'message' => __('messages.fee_plan.updated'),
            'data' => new FeePlanResource($feePlan->fresh(['installments.activeAllocations', 'activePayments'])),
        ]);
    }

    /** تعديل جدول الأقساط وحده: تواريخ ومبالغ، بلا مساس بالإيصالات. */
    public function updateInstallments(UpdateFeePlanInstallmentsRequest $request, FeePlan $feePlan): JsonResponse
    {
        $this->authorize('update', $feePlan);

        FeePlanBuilder::writeInstallments(
            $feePlan,
            FeePlanBuilder::normalise($request->input('installments', [])),
        );

        return response()->json([
            'message' => __('messages.fee_plan.installments_updated'),
            'data' => new FeePlanResource($feePlan->fresh(['installments.activeAllocations', 'activePayments'])),
        ]);
    }

    public function destroy(FeePlan $feePlan): JsonResponse
    {
        $this->authorize('delete', $feePlan);

        // حتى الإيصال الملغى يمنع الحذف: تسلسل الإيصالات لا تجوز فيه فجوة.
        if ($feePlan->payments()->exists()) {
            return response()->json(['message' => __('messages.fee_plan.has_payments')], 422);
        }

        $feePlan->delete();

        return response()->json(['message' => __('messages.fee_plan.deleted')]);
    }

    /** كل خطط طالب واحد، عبر السنوات. */
    public function forStudent(Request $request, Student $student): AnonymousResourceCollection
    {
        $this->authorize('viewFees', $student);

        $plans = $student->feePlans()
            ->with(['academicYear', 'installments.activeAllocations', 'activePayments', 'payments.allocations'])
            ->orderByDesc('created_at')
            ->get();

        return FeePlanResource::collection($plans);
    }

    /** كشف حساب الطالب: كل زيادة وكل تسديد برصيد جارٍ. */
    public function statement(Request $request, Student $student): JsonResponse
    {
        $this->authorize('viewFees', $student);

        $statement = FeeStatement::forStudent($student);

        return response()->json([
            'data' => [
                ...$statement,
                'billed' => $statement['billed']->toDecimal(),
                'discount' => $statement['discount']->toDecimal(),
                'net' => $statement['net']->toDecimal(),
                'paid' => $statement['paid']->toDecimal(),
                'remaining' => $statement['remaining']->toDecimal(),
            ],
        ]);
    }

    /** لوحة الأرقام: المفوتر والمخصوم والمحصَّل والمتبقّي والمتأخر. */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', FeePlan::class);
        $this->authorize('create', FeePlan::class);

        return response()->json([
            'data' => FeeStatement::summary(
                $request->user()->school_id,
                $request->filled('academic_year_id') ? $request->integer('academic_year_id') : null,
            ),
        ]);
    }
}
