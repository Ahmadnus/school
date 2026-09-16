<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\BehaviorStatus;
use App\Enums\BehaviorType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BehaviorRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'recorded_by',
        'type',
        'category',
        'title',
        'description',
        'occurred_on',
        'action_taken',
        'status',
        'visible_to_guardian',
    ];

    protected $attributes = [
        'status' => BehaviorStatus::Open->value,
        'visible_to_guardian' => false,
    ];

    protected function casts(): array
    {
        return [
            'type' => BehaviorType::class,
            'status' => BehaviorStatus::class,
            'occurred_on' => DateOnly::class,
            'visible_to_guardian' => 'boolean',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeOfSchool(Builder $query, int $schoolId): Builder
    {
        return $query->whereHas('student', fn (Builder $q) => $q->ofSchool($schoolId));
    }

    /** What the guardian app is allowed to see. */
    public function scopeSharedWithGuardian(Builder $query): Builder
    {
        return $query->where('visible_to_guardian', true);
    }
}
