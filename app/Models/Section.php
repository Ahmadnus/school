<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Section extends Model
{
    use HasFactory;

    protected $fillable = ['grade_id', 'academic_year_id', 'name', 'capacity', 'sort_order'];

    protected function casts(): array
    {
        return ['capacity' => 'integer'];
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class);
    }

    /** Students of this section — always through the enrollment table. */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'student_enrollments')
            ->withPivot(['academic_year_id', 'scope', 'enrolled_at', 'transport_subscribed', 'status'])
            ->withTimestamps();
    }

    public function teacherAssignments(): HasMany
    {
        return $this->hasMany(TeacherAssignment::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function attendanceSessions(): HasMany
    {
        return $this->hasMany(AttendanceSession::class);
    }

    public function scheduleSlots(): HasMany
    {
        return $this->hasMany(ScheduleSlot::class);
    }

    public function supervisors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'supervisor_scopes', 'section_id', 'staff_id')
            ->withTimestamps();
    }

    public function scopeOfYear(Builder $query, int $academicYearId): Builder
    {
        return $query->where('academic_year_id', $academicYearId);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
