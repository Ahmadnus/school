<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Grade extends Model
{
    use HasFactory;

    protected $fillable = ['school_id', 'name', 'sort_order'];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    /** Sections of this grade in one academic year — defaults to the current one. */
    public function sectionsForYear(?int $academicYearId = null): HasMany
    {
        return $this->sections()->where(
            'academic_year_id',
            $academicYearId ?? AcademicYear::currentFor($this->school_id)?->id,
        );
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class);
    }

    public function scopeOfSchool(Builder $query, int $schoolId): Builder
    {
        return $query->where('school_id', $schoolId);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
