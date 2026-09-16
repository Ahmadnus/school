<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * الجزء من دفعة الذي يغطّي قسطاً بعينه. مجموع توزيعات الدفعة يساوي مبلغها
 * دائماً؛ صف بلا قسط هو رصيد مقدّم لم يُنسب بعد.
 */
class FeePaymentAllocation extends Model
{
    use HasFactory;

    protected $fillable = ['fee_payment_id', 'fee_plan_installment_id', 'amount_minor'];

    protected function casts(): array
    {
        return ['amount_minor' => MoneyCast::class];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(FeePayment::class, 'fee_payment_id');
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(FeePlanInstallment::class, 'fee_plan_installment_id');
    }

    public function amount(): Money
    {
        return $this->amount_minor;
    }
}
