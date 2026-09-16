<?php

namespace App\Models;

use App\Enums\ReportCardStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportCard extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'term_id',
        'academic_year_id',
        'supervisor_notes',
        'average',
        'status',
        'published_at',
        'created_by',
    ];

    protected $attributes = [
        'status' => ReportCardStatus::Draft->value,
    ];

    protected function casts(): array
    {
        return [
            'average' => 'decimal:2',
            'status' => ReportCardStatus::class,
            'published_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /** Only populated once published (decision 11-c). */
    public function lines(): HasMany
    {
        return $this->hasMany(ReportCardLine::class)->orderBy('sort_order');
    }

    public function isDraft(): bool
    {
        return $this->status === ReportCardStatus::Draft;
    }

    public function scopeOfSchool(Builder $query, int $schoolId): Builder
    {
        return $query->whereHas('student', fn (Builder $q) => $q->ofSchool($schoolId));
    }
}
