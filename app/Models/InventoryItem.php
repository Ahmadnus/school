<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** صنف مخزون (قرطاسية، مستهلكات). الرصيد يتغيّر بالحركات فقط. */
class InventoryItem extends Model
{
    use Audited;

    protected $fillable = ['school_id', 'name', 'sku', 'unit', 'min_quantity', 'notes'];

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'item_id');
    }

    public function isLow(): bool
    {
        return $this->min_quantity > 0 && $this->quantity <= $this->min_quantity;
    }

    public function auditLabel(): ?string
    {
        return $this->name;
    }
}
