<?php

namespace App\Models;

use App\Enums\GradingMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Subject extends Model
{
    use HasFactory;

    protected $fillable = [
        'grade_id',
        'term_id',
        'name',
        'grading_method',
        'max_score',
        'pass_score',
        'periods_per_week',
    ];

    protected $attributes = [
        'grading_method' => GradingMethod::Numeric->value,
        'max_score' => 100,
        'pass_score' => 50,
        'periods_per_week' => 3,
    ];

    protected function casts(): array
    {
        return [
            'grading_method' => GradingMethod::class,
            'max_score' => 'decimal:2',
            'pass_score' => 'decimal:2',
            'periods_per_week' => 'integer',
        ];
    }

    /**
     * سعر تسجيل هذه المادة منفردةً.
     *
     * نوع الرسوم هو حامل السعر في النظام كلّه، فلا يُضاف عمود ثانٍ للمال
     * على المادة: مصدران للسعر يفترقان عند أول تعديل.
     */
    public function feeType(): HasOne
    {
        return $this->hasOne(FeeType::class)->where('status', 'active');
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TeacherAssignment::class);
    }

    /** Teachers assigned to this subject, in any section. */
    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'teacher_assignments', 'subject_id', 'staff_id')
            ->withPivot('section_id')
            ->withTimestamps();
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    public function scheduleSlots(): HasMany
    {
        return $this->hasMany(ScheduleSlot::class);
    }

    public function scopeOfSchool(Builder $query, int $schoolId): Builder
    {
        return $query->whereHas('grade', fn (Builder $q) => $q->where('school_id', $schoolId));
    }

    /** The subjects list is always filtered by grade and term together. */
    public function scopeForGradeAndTerm(Builder $query, int $gradeId, int $termId): Builder
    {
        return $query->where('grade_id', $gradeId)->where('term_id', $termId);
    }
}
