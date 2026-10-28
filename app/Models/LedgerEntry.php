<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Casts\MoneyCast;
use App\Enums\PaymentMethod;
use App\Models\Concerns\Audited;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * قيد إيراد/مصروف. لا يُحذف: الإلغاء يترك الصف مع سببه ومن ألغاه.
 */
class LedgerEntry extends Model
{
    use Audited;

    protected $fillable = [
        'school_id',
        'ledger_category_id',
        'kind',
        'entry_date',
        'amount_minor',
        'method',
        'reference',
        'description',
        'idempotency_key',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => DateOnly::class,
            'amount_minor' => MoneyCast::class,
            'method' => PaymentMethod::class,
            'voided_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(LedgerCategory::class, 'ledger_category_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function amount(): Money
    {
        return $this->amount_minor;
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function auditLabel(): ?string
    {
        return $this->amount()->toDecimal().' — '.($this->description ?? $this->category?->name);
    }
}
