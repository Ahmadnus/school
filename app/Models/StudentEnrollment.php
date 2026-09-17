<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\EnrollmentScope;
use App\Enums\EnrollmentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class StudentEnrollment extends Model
{
    use HasFactory;


    protected $fillable = [
        'student_id',
        'section_id',
        'academic_year_id',
        'scope',
        'enrolled_at',
        'transport_subscribed',
        'status',
    ];

    /** Mirrors the column defaults so a freshly created model is complete. */
    protected $attributes = [
        'scope' => EnrollmentScope::FullYear->value,
        'transport_subscribed' => false,
        'status' => EnrollmentStatus::Active->value,
    ];

    protected function casts(): array
    {
        return [
            'enrolled_at' => DateOnly::class,
            'transport_subscribed' => 'boolean',
            'scope' => EnrollmentScope::class,
            'status' => EnrollmentStatus::class,
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * المواد المسجّلة — **فارغة تعني الخطة الكاملة** لا «بلا مواد».
     *
     * الغالبية يدرسون البرنامج كلّه، فتسجيل كل مادة لكل طالب يضخّم الجدول
     * بلا معلومة إضافية. استعمل [studiesSubjects] للتمييز.
     */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(
            Subject::class,
            'enrollment_subjects',
            'student_enrollment_id',
            'subject_id',
        )->withTimestamps();
    }

    /** هل هذا تسجيل مواد مختارة؟ */
    public function studiesSelectedSubjects(): bool
    {
        return $this->subjects()->exists();
    }

    public function scopeOfYear(Builder $query, int $academicYearId): Builder
    {
        return $query->where('academic_year_id', $academicYearId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', EnrollmentStatus::Active);
    }
}
