<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\Gender;
use App\Enums\Status;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class Student extends Model
{
    use HasFactory;


    protected $fillable = [
        'school_id',
        'student_number',
        'external_id',
        'first_name',
        'last_name',
        'birth_date',
        'gender',
        'nationality',
        'blood_type',
        'address',
        'building',
        'medical_notes',
        'phone',
        'email',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relation',
        'status',
    ];

    protected $attributes = [
        'status' => Status::Active->value,
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => DateOnly::class,
            'gender' => Gender::class,
            'status' => Status::class,
        ];
    }

    protected static function booted(): void
    {
        // student_number is a per-school running number (decision 20-a),
        // allocated by StudentCounter so concurrent writers cannot collide.
        static::creating(function (self $student) {
            $student->student_number ??= StudentCounter::reserve($student->school_id);
        });
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class);
    }

    /**
     * The enrollment in the school's active academic year.
     * Everything year-specific about a student hangs off this, never off the student row.
     */
    public function currentEnrollment(): HasOne
    {
        return $this->hasOne(StudentEnrollment::class)
            ->whereHas('academicYear', fn (Builder $query) => $query->where('is_current', true));
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function absenceExcuses(): HasMany
    {
        return $this->hasMany(AbsenceExcuse::class);
    }

    public function cards(): HasMany
    {
        return $this->hasMany(StudentCard::class);
    }

    /** The card the gate reader should match (decision 16-b). */
    public function activeCard(): HasOne
    {
        return $this->hasOne(StudentCard::class)->where('is_active', true);
    }

    public function feePlans(): HasMany
    {
        return $this->hasMany(FeePlan::class);
    }

    public function scores(): HasMany
    {
        return $this->hasMany(GradeScore::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(StudentNote::class);
    }

    public function behaviorRecords(): HasMany
    {
        return $this->hasMany(BehaviorRecord::class);
    }

    public function reportCards(): HasMany
    {
        return $this->hasMany(ReportCard::class);
    }

    /** True when this guardian account is linked to the student. */
    public function isGuardedBy(User $user): bool
    {
        return $this->guardians()->where('guardians.user_id', $user->id)->exists();
    }

    public function guardianLinks(): HasMany
    {
        return $this->hasMany(StudentGuardian::class);
    }

    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(Guardian::class, 'student_guardians')
            ->withPivot(['relation', 'is_primary'])
            ->withTimestamps();
    }

    /** The single primary contact (decision 14-a). */
    public function primaryGuardian(): HasOneThrough
    {
        return $this->hasOneThrough(
            Guardian::class,
            StudentGuardian::class,
            'student_id',
            'id',
            'id',
            'guardian_id',
        )->where('student_guardians.is_primary', true);
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function scopeOfSchool(Builder $query, int $schoolId): Builder
    {
        return $query->where('school_id', $schoolId);
    }

    /**
     * Restrict to students enrolled in the active academic year.
     *
     * This is the entry point for every "students of X" query — pass a closure to
     * narrow the enrollment further (by section, transport, enrollment status …).
     *
     * @param  (\Closure(Builder): mixed)|null  $constrain
     */
    public function scopeCurrentYear(Builder $query, ?\Closure $constrain = null): Builder
    {
        return $query->whereHas('currentEnrollment', function (Builder $enrollment) use ($constrain) {
            if ($constrain) {
                $constrain($enrollment);
            }
        });
    }

    /** Students of a section, in the active year. */
    public function scopeInSection(Builder $query, int $sectionId): Builder
    {
        return $query->currentYear(fn (Builder $e) => $e->where('section_id', $sectionId));
    }

    /** Students of a grade, in the active year — via that year's sections. */
    public function scopeInGrade(Builder $query, int $gradeId): Builder
    {
        return $query->currentYear(fn (Builder $e) => $e->whereHas(
            'section',
            fn (Builder $section) => $section->where('grade_id', $gradeId),
        ));
    }
}
