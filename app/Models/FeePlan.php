<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\FeePlanStatus;
use App\Enums\PaymentState;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * خطة رسوم طالب في سنة أكاديمية.
 *
 * ثابتان يحكمان كل ما تحته:
 *   1. الصافي = الإجمالي − الخصم، ومجموع أقساط الخطة يساوي الصافي دائماً.
 *   2. المدفوع = مجموع الدفعات غير الملغاة، ويساوي مجموع توزيعاتها.
 * كل تعديل على الخطة يُرفض إن كسر أياً منهما.
 */
class FeePlan extends Model
{
    use HasFactory;

    /** SQL للمدفوع: يستثني الملغى، ويُستعمل في الفلاتر والتقارير. */
    public const PAID_SQL = '(select coalesce(sum(amount_minor), 0) from fee_payments'
        .' where fee_payments.fee_plan_id = fee_plans.id and fee_payments.voided_at is null)';

    public const NET_SQL = '(fee_plans.total_minor - fee_plans.discount_minor)';

    protected $fillable = [
        'student_id',
        'academic_year_id',
        'fee_type_id',
        'total_minor',
        'discount_minor',
        'discount_reason',
        'notes',
        'payment_reminders',
        'status',
    ];

    protected $attributes = [
        'discount_minor' => 0,
        'payment_reminders' => true,
        'status' => FeePlanStatus::Active->value,
    ];

    protected function casts(): array
    {
        return [
            'total_minor' => MoneyCast::class,
            'discount_minor' => MoneyCast::class,
            'payment_reminders' => 'boolean',
            'status' => FeePlanStatus::class,
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /** مرجع تاريخي فقط — الخطة لا تقرأ أرقامها من النوع (قرار 2-أ). */
    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FeeType::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(FeePlanInstallment::class)->orderBy('sort_order');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FeePayment::class);
    }

    /** ما يدخل في الحساب: الدفعات غير الملغاة. */
    public function activePayments(): HasMany
    {
        return $this->payments()->whereNull('voided_at');
    }

    public function totalAmount(): Money
    {
        return $this->total_minor;
    }

    public function discountAmount(): Money
    {
        return $this->discount_minor;
    }

    /** المستحق فعلاً بعد الخصم. */
    public function netAmount(): Money
    {
        return $this->totalAmount()->minus($this->discountAmount());
    }

    public function paidAmount(): Money
    {
        if ($this->relationLoaded('activePayments')) {
            return Money::sum($this->activePayments->map(fn (FeePayment $p) => $p->amount()));
        }

        return Money::fromMinor((int) $this->activePayments()->sum('amount_minor'));
    }

    public function remainingAmount(): Money
    {
        return $this->netAmount()->minus($this->paidAmount())->clampToZero();
    }

    /** موجب فقط لو سُجّل أكثر من المستحق — يجب ألا يحدث، ويُعرض إن حدث. */
    public function overpaidAmount(): Money
    {
        return $this->paidAmount()->minus($this->netAmount())->clampToZero();
    }

    public function hasDiscount(): bool
    {
        return $this->discountAmount()->isPositive();
    }

    public function paymentState(): PaymentState
    {
        $paid = $this->paidAmount();

        return match (true) {
            ! $paid->isPositive() => PaymentState::Unpaid,
            ! $paid->lessThan($this->netAmount()) => PaymentState::Paid,
            default => PaymentState::PartiallyPaid,
        };
    }

    /** أقدم قسط مستحق لم يُسدَّد، أو null. */
    public function overdueSince(?Carbon $asOf = null): ?Carbon
    {
        return $this->installments
            ->first(fn (FeePlanInstallment $i) => $i->isOverdue($asOf))
            ?->due_date;
    }

    public function scopeOfSchool(Builder $query, int $schoolId): Builder
    {
        return $query->whereHas('student', fn (Builder $q) => $q->ofSchool($schoolId));
    }

    /** شرائح الحالة. محسوبة من مجموع الدفعات، فتُعبَّر SQL لا تُخزَّن. */
    public function scopeWithPaymentState(Builder $query, PaymentState $state): Builder
    {
        $paid = self::PAID_SQL;
        $net = self::NET_SQL;

        return match ($state) {
            PaymentState::Unpaid => $query->whereRaw("$paid <= 0"),
            PaymentState::Paid => $query->whereRaw("$paid >= $net")->whereRaw("$paid > 0"),
            PaymentState::PartiallyPaid => $query->whereRaw("$paid > 0")->whereRaw("$paid < $net"),
        };
    }

    /** فلتر «الطلاب أصحاب الخصومات». */
    public function scopeHasDiscount(Builder $query, bool $has = true): Builder
    {
        return $has
            ? $query->where('discount_minor', '>', 0)
            : $query->where('discount_minor', '<=', 0);
    }

    /** فلتر «متأخر»: قسط استحق ولم يُغطَّ بعد. */
    public function scopeOverdue(Builder $query, ?Carbon $asOf = null): Builder
    {
        $date = ($asOf ?? Carbon::today())->toDateString();

        return $query->whereHas('installments', function (Builder $q) use ($date) {
            $q->whereDate('due_date', '<', $date)
                ->whereRaw(
                    'fee_plan_installments.amount_minor > (select coalesce(sum(a.amount_minor), 0)'
                    .' from fee_payment_allocations a'
                    .' join fee_payments p on p.id = a.fee_payment_id'
                    .' where a.fee_plan_installment_id = fee_plan_installments.id and p.voided_at is null)',
                );
        });
    }
}
