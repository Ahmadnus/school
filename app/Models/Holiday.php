<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Holiday extends Model
{
    use HasFactory;

    protected $fillable = ['school_id', 'academic_year_id', 'name', 'start_date', 'end_date'];

    protected function casts(): array
    {
        return [
            'start_date' => DateOnly::class,
            'end_date' => DateOnly::class,
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function scopeOfSchool(Builder $query, int $schoolId): Builder
    {
        return $query->where('school_id', $schoolId);
    }

    /** Holidays overlapping a date window. */
    public function scopeOverlapping(Builder $query, string $from, string $to): Builder
    {
        return $query->where('start_date', '<=', $to)->where('end_date', '>=', $from);
    }
}
