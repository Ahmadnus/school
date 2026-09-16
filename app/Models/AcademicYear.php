<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class AcademicYear extends Model
{
    use HasFactory;


    protected $fillable = ['school_id', 'name', 'start_date', 'end_date', 'is_current'];

    protected function casts(): array
    {
        return [
            'start_date' => DateOnly::class,
            'end_date' => DateOnly::class,
            'is_current' => 'boolean',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function terms(): HasMany
    {
        return $this->hasMany(Term::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class);
    }

    public function scopeOfSchool(Builder $query, int $schoolId): Builder
    {
        return $query->where('school_id', $schoolId);
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }

    /** The active year of a school — the anchor for every year-scoped query. */
    public static function currentFor(int $schoolId): ?self
    {
        return static::query()->ofSchool($schoolId)->current()->first();
    }

    /** Exactly one current year per school. */
    public function markAsCurrent(): void
    {
        DB::transaction(function () {
            static::query()
                ->ofSchool($this->school_id)
                ->whereKeyNot($this->getKey())
                ->update(['is_current' => false]);

            $this->forceFill(['is_current' => true])->save();
        });
    }
}
