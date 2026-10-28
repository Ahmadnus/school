<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** طلب إجازة لموظف: معلّق ← موافَق / مرفوض. */
class LeaveRequest extends Model
{
    use Audited;

    public const TYPES = ['annual', 'sick', 'unpaid', 'other'];

    protected $fillable = ['school_id', 'user_id', 'type', 'from_date', 'to_date', 'reason', 'status'];

    protected function casts(): array
    {
        return [
            'from_date' => DateOnly::class,
            'to_date' => DateOnly::class,
            'decided_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditLabel(): ?string
    {
        return $this->type.' '.$this->from_date?->toDateString().' → '.$this->to_date?->toDateString();
    }
}
