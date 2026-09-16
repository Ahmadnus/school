<?php

namespace App\Models;

use App\Enums\Weekday;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleSlot extends Model
{
    use HasFactory;

    protected $fillable = [
        'section_id',
        'term_id',
        'subject_id',
        'staff_id',
        'day_of_week',
        'starts_at',
        'ends_at',
        'period_number',
        'room',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => Weekday::class,
            'period_number' => 'integer',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function scopeOfSchool(Builder $query, int $schoolId): Builder
    {
        return $query->whereHas('subject', fn (Builder $q) => $q->ofSchool($schoolId));
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('day_of_week')->orderBy('period_number');
    }
}
