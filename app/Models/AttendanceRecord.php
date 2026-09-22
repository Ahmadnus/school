<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Enums\ExcuseStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'section_id',
        'date',
        'status',
        'source',
        'recorded_by',
        'recorded_at',
    ];

    protected $attributes = [
        'source' => AttendanceSource::Manual->value,
    ];

    protected function casts(): array
    {
        return [
            'date' => DateOnly::class,
            'status' => AttendanceStatus::class,
            'source' => AttendanceSource::class,
            'recorded_at' => 'datetime',
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

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeOfSchool(Builder $query, int $schoolId): Builder
    {
        return $query->whereHas('student', fn (Builder $q) => $q->ofSchool($schoolId));
    }

    /**
     * Absences covered by an accepted excuse. This is the derived fourth state
     * of the summary bar — it is computed here, never stored (decision 4-a).
     */
    public function scopeExcused(Builder $query): Builder
    {
        return $query
            ->where('status', AttendanceStatus::Absent)
            ->whereExists(fn ($sub) => $sub
                ->selectRaw('1')
                ->from('absence_excuses')
                ->whereColumn('absence_excuses.student_id', 'attendance_records.student_id')
                ->where('absence_excuses.status', ExcuseStatus::Accepted->value)
                ->whereColumn('absence_excuses.start_date', '<=', 'attendance_records.date')
                ->whereColumn('absence_excuses.end_date', '>=', 'attendance_records.date'));
    }

    /** Absences with no accepted excuse. */
    public function scopeUnexcused(Builder $query): Builder
    {
        return $query
            ->where('status', AttendanceStatus::Absent)
            ->whereNotExists(fn ($sub) => $sub
                ->selectRaw('1')
                ->from('absence_excuses')
                ->whereColumn('absence_excuses.student_id', 'attendance_records.student_id')
                ->where('absence_excuses.status', ExcuseStatus::Accepted->value)
                ->whereColumn('absence_excuses.start_date', '<=', 'attendance_records.date')
                ->whereColumn('absence_excuses.end_date', '>=', 'attendance_records.date'));
    }

    /** True when this absence is covered by an accepted excuse. */
    public function isExcused(): bool
    {
        if ($this->status !== AttendanceStatus::Absent) {
            return false;
        }

        return AbsenceExcuse::query()
            ->where('student_id', $this->student_id)
            ->where('status', ExcuseStatus::Accepted)
            ->where('start_date', '<=', $this->date->toDateString())
            ->where('end_date', '>=', $this->date->toDateString())
            ->exists();
    }
}
