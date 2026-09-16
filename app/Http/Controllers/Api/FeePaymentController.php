<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fee\StoreFeePaymentRequest;
use App\Http\Requests\Fee\VoidFeePaymentRequest;
use App\Http\Resources\FeePaymentResource;
use App\Http\Resources\FeePlanResource;
use App\Models\FeePayment;
use App\Models\FeePlan;
use App\Services\PaymentRecorder;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class FeePaymentController extends Controller
{
    public function index(FeePlan $feePlan): AnonymousResourceCollection
    {
        $this->authorize('view', $feePlan);

        return FeePaymentResource::collection(
            $feePlan->payments()
                ->with(['allocations', 'recorder'])
                ->orderByDesc('paid_on')
                ->orderByDesc('receipt_number')
                ->get(),
        );
    }

    public function store(StoreFeePaymentRequest $request, FeePlan $feePlan): JsonResponse
    {
        $this->authorize('pay', $feePlan);

        $payment = PaymentRecorder::record(
            plan: $feePlan,
            amount: Money::fromDecimal($request->input('amount')),
            paidOn: Carbon::parse($request->input('paid_on')),
            recordedBy: $request->user(),
            method: PaymentMethod::from($request->input('method', PaymentMethod::Cash->value)),
            installmentId: $request->integer('installment_id') ?: null,
            description: $request->input('description'),
            reference: $request->input('reference'),
            idempotencyKey: $request->input('idempotency_key'),
        );

        return response()->json([
            'message' => __('messages.fee_payment.created'),
            'data' => new FeePaymentResource($payment->load('allocations')),
            // بطاقة الطالب تتحرّك مع الإيصال: المدفوع والمتبقّي وحالة كل قسط.
            'plan' => new FeePlanResource($feePlan->fresh(['installments.activeAllocations', 'activePayments'])),
        ], 201);
    }

    /**
     * لا حذف للإيصالات. الإلغاء يُبقي الصف ورقمه ويسجّل السبب ومن ألغى، فيبقى
     * كل مبلغ دخل الصندوق مرئياً في الكشف ولو لم يعد محتسباً.
     */
    public function void(VoidFeePaymentRequest $request, FeePayment $payment): JsonResponse
    {
        $this->authorize('pay', $payment->plan);

        $payment = PaymentRecorder::void($payment, $request->user(), $request->input('reason'));

        return response()->json([
            'message' => __('messages.fee_payment.voided'),
            'data' => new FeePaymentResource($payment),
            'plan' => new FeePlanResource(
                $payment->plan->fresh(['installments.activeAllocations', 'activePayments']),
            ),
        ]);
    }
}
