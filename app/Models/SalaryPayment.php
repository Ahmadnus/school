<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Casts\MoneyCast;
use App\Enums\PaymentMethod;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * صرف راتب شهر لموظف. لا يُحذف: الإلغاء يترك الصف مع سببه ويُلغي قيده المحاسبي.
 * net = الأساسي + العلاوة − الخصم (محسوب في الخادم، لا يُستلم من العميل).
 */
class SalaryPayment extends Model
{
    use Audited;

    protected $fillable = [
        'school_id', 'user_id', 'month', 'base_minor', 'bonus_minor', 'deduction_minor',
        'net_minor', 'method', 'paid_on', 'note', 'ledger_entry_id', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'base_minor' => MoneyCast::class,
            'bonus_minor' => MoneyCast::class,
            'deduction_minor' => MoneyCast::class,
            'net_minor' => MoneyCast::class,
            'method' => PaymentMethod::class,
            'paid_on' => DateOnly::class,
            'voided_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function auditLabel(): ?string
    {
        return $this->month.' — '.$this->net_minor->toDecimal();
    }
}
