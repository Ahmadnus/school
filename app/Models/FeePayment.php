<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Casts\MoneyCast;
use App\Enums\PaymentMethod;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * إيصال قبض. لا يُحذف أبداً — الإلغاء يترك الصف مكانه مع سببه ومن ألغاه،
 * فيبقى تسلسل أرقام الإيصالات متصلاً وقابلاً للتدقيق.
 */
class FeePayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'fee_plan_id',
        'receipt_number',
        'paid_on',
        'amount_minor',
        'method',
        'description',
        'reference',
        'idempotency_key',
        'recorded_by',
    ];

    protected $attributes = [
        'method' => PaymentMethod::Cash->value,
    ];

    protected function casts(): array
    {
        return [
            'paid_on' => DateOnly::class,
            'amount_minor' => MoneyCast::class,
            'method' => PaymentMethod::class,
            'voided_at' => 'datetime',
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

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function amount(): Money
    {
        return $this->amount_minor;
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /** الدفعات التي تُحتسب في الأرصدة: كل ما لم يُلغَ. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function scopeOfSchool(Builder $query, int $schoolId): Builder
    {
        return $query->where('school_id', $schoolId);
    }
}
