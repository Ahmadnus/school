<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\ExcuseStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AbsenceExcuse extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'start_date',
        'end_date',
        'reason',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected $attributes = [
        'status' => ExcuseStatus::Pending->value,
    ];

    protected function casts(): array
    {
        return [
            'start_date' => DateOnly::class,
            'end_date' => DateOnly::class,
            'status' => ExcuseStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeOfSchool(Builder $query, int $schoolId): Builder
    {
        return $query->whereHas('student', fn (Builder $q) => $q->ofSchool($schoolId));
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ExcuseStatus::Pending);
    }

    /** Excuses whose range covers a given day. */
    public function scopeCovering(Builder $query, string $date): Builder
    {
        return $query->where('start_date', '<=', $date)->where('end_date', '>=', $date);
    }
}
