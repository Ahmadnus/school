<?php

namespace App\Console\Commands;

use App\Enums\FeePlanStatus;
use App\Enums\NotificationApp;
use App\Models\FeePlanInstallment;
use App\Models\Notification;
use App\Services\NotificationGate;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Reminds families of an installment that is due.
 *
 * `fee_due` is the one key in the catalog with no action behind it: every
 * other notification is triggered by somebody pressing something, while an
 * installment simply *arrives*. So it needs a daily pass.
 *
 * Runs for installments due in `--days` days (default 3) and for ones already
 * overdue, and sends **once per installment** — the guard is a lookup of an
 * existing `fee_due` notification with the same `ref_id`, so running the
 * command twice in a day, or re-running it after a failure, does not nag a
 * parent twice about the same money.
 */
class NotifyDueInstallments extends Command
{
    protected $signature = 'fees:notify-due {--days=3 : How many days ahead to look}';

    protected $description = 'Notify guardians of installments coming due or overdue';

    public function handle(): int
    {
        $today = Carbon::today();
        $horizon = $today->copy()->addDays((int) $this->option('days'));

        $installments = FeePlanInstallment::query()
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $horizon)
            ->whereHas('plan', fn ($q) => $q->where('status', '!=', FeePlanStatus::Cancelled))
            ->with(['plan.student.guardians.user', 'activeAllocations'])
            ->get();

        $sent = 0;

        foreach ($installments as $installment) {
            // A settled installment is not news; the amount is what decides,
            // not the date — a parent who paid early must hear nothing.
            if (! $installment->remainingAmount()->isPositive()) {
                continue;
            }

            $student = $installment->plan?->student;

            if (! $student) {
                continue;
            }

            if (self::alreadySent($installment->id)) {
                continue;
            }

            $overdue = $installment->isOverdue($today);
            $due = $installment->due_date->format('Y-m-d');
            $remaining = (string) $installment->remainingAmount()->toDecimal();

            foreach ($student->guardians as $guardian) {
                if (! $guardian->user) {
                    continue;
                }

                NotificationGate::notify(
                    $guardian->user,
                    'fee_due',
                    __('notifications.fee_due_title', ['name' => $student->full_name]),
                    __($overdue ? 'notifications.fee_overdue_body' : 'notifications.fee_due_body', [
                        'date' => $due,
                        'amount' => $remaining,
                    ]),
                    $installment->id,
                    NotificationApp::Guardian,
                );
                $sent++;
            }
        }

        $this->info("fee_due sent: {$sent}");

        return self::SUCCESS;
    }

    /** One reminder per installment, however often this command runs. */
    private static function alreadySent(int $installmentId): bool
    {
        return Notification::query()
            ->where('type', 'fee_due')
            ->where('ref_id', $installmentId)
            ->exists();
    }
}
