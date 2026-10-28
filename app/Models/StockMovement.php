<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** حركة مخزون: لا تُعدَّل ولا تُحذف، التصحيح بحركة تسوية. */
class StockMovement extends Model
{
    use Audited;

    protected $fillable = ['school_id', 'item_id', 'type', 'delta', 'balance_after', 'reason', 'recorded_by'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function auditLabel(): ?string
    {
        return $this->type.' '.$this->delta;
    }
}
