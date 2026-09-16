<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Casts\MoneyCast;
use App\Enums\InstallmentState;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class FeePlanInstallment extends Model
{
    use HasFactory;

    protected $fillable = ['fee_plan_id', 'sort_order', 'due_date', 'amount_minor', 'notes'];

    protected function casts(): array
    {
        return [
            'due_date' => DateOnly::class,
            'amount_minor' => MoneyCast::class,
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(FeePlan::class, 'fee_plan_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(FeePaymentAllocation::class);
    }

    /** التوزيعات المحتسبة فقط — الدفعة الملغاة لا تُسدّد قسطاً. */
    public function activeAllocations(): HasMany
    {
        return $this->allocations()->whereHas('payment', fn ($q) => $q->whereNull('voided_at'));
    }

    public function amount(): Money
    {
        return $this->amount_minor;
    }

    public function paidAmount(): Money
    {
        if ($this->relationLoaded('activeAllocations')) {
            return Money::sum($this->activeAllocations->map(fn (FeePaymentAllocation $a) => $a->amount()));
        }

        return Money::fromMinor((int) $this->activeAllocations()->sum('amount_minor'));
    }

    public function remainingAmount(): Money
    {
        return $this->amount()->minus($this->paidAmount())->clampToZero();
    }

    public function isOverdue(?Carbon $asOf = null): bool
    {
        return $this->remainingAmount()->isPositive()
            && $this->due_date?->lt($asOf ?? Carbon::today()) === true;
    }

    public function state(?Carbon $asOf = null): InstallmentState
    {
        $paid = $this->paidAmount();

        if (! $paid->lessThan($this->amount())) {
            return InstallmentState::Paid;
        }

        if ($this->isOverdue($asOf)) {
            return InstallmentState::Overdue;
        }

        return $paid->isPositive() ? InstallmentState::PartiallyPaid : InstallmentState::Unpaid;
    }
}
