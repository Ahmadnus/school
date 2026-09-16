<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeTypeInstallment extends Model
{
    use HasFactory;

    protected $fillable = ['fee_type_id', 'sort_order', 'due_date', 'amount_minor', 'notes'];

    protected function casts(): array
    {
        return [
            'due_date' => DateOnly::class,
            'amount_minor' => MoneyCast::class,
        ];
    }

    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FeeType::class);
    }

    public function amount(): Money
    {
        return $this->amount_minor;
    }
}
