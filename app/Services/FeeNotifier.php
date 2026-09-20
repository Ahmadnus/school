<?php

namespace App\Services;

use App\Enums\NotificationApp;
use App\Models\FeePayment;
use App\Models\FeePlan;
use App\Support\Money;

/**
 * What a recorded payment tells the family.
 *
 * `fee_payment_recorded` sat in the notification catalog — visible as a switch
 * in both settings screens — while no code ever sent it. A parent paid at the
 * office and got a paper receipt; anyone not standing there learned nothing,
 * and the remaining balance in the app looked unchanged until they opened it.
 *
 * The notification carries the two numbers a parent actually asks for: what
 * was received, and what is still owed. The receipt number is included so the
 * message can be matched to the paper in hand.
 */
class FeeNotifier
{
    public static function paymentRecorded(FeePayment $payment): void
    {
        $payment->loadMissing('plan.student.guardians.user');
        $plan = $payment->plan;
        $student = $plan?->student;

        if (! $student) {
            return;
        }

        $remaining = $plan->remainingAmount();

        foreach ($student->guardians as $guardian) {
            if (! $guardian->user) {
                continue;
            }

            NotificationGate::notify(
                $guardian->user,
                'fee_payment_recorded',
                __('notifications.fee_payment_title', ['name' => $student->full_name]),
                __('notifications.fee_payment_body', [
                    'amount' => self::money($payment->amount_minor),
                    'receipt' => $payment->receipt_number,
                    'remaining' => self::money($remaining),
                ]),
                $plan->id,
                NotificationApp::Guardian,
            );
        }
    }

    /**
     * Money crosses into text exactly once, here.
     *
     * The amounts are minor units (`Money`); formatting them anywhere else
     * invites a float to sneak in and turn 2,000,000 into 1,999,999.99.
     */
    private static function money(Money|int|null $value): string
    {
        if ($value instanceof Money) {
            return (string) $value->toDecimal();
        }

        return (string) Money::fromMinor((int) ($value ?? 0))->toDecimal();
    }

    /** True when the plan has nothing left to pay — used by the caller's wording. */
    public static function isSettled(FeePlan $plan): bool
    {
        return ! $plan->remainingAmount()->isPositive();
    }
}
