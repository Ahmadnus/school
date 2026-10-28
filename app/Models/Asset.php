<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Casts\MoneyCast;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** أصل ثابت: أثاث أو جهاز، مع موقعه وعهدته وحالته. */
class Asset extends Model
{
    use Audited;

    public const STATUSES = ['active', 'maintenance', 'retired'];

    protected $fillable = [
        'school_id', 'name', 'code', 'category', 'location', 'purchased_on',
        'cost_minor', 'status', 'assigned_to', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'purchased_on' => DateOnly::class,
            'cost_minor' => MoneyCast::class,
        ];
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function auditLabel(): ?string
    {
        return $this->name;
    }
}
