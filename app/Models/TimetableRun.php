<?php

namespace App\Models;

use App\Enums\TimetableRunStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * محاولة توليد جدول: ما طُلب، وما وُجد، وهل نجحت.
 */
class TimetableRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'term_id',
        'status',
        'requested_by',
        'lesson_minutes',
        'stats',
        'conflicts',
        'lessons_placed',
        'lessons_required',
    ];

    protected function casts(): array
    {
        return [
            'status' => TimetableRunStatus::class,
            'stats' => 'array',
            'conflicts' => 'array',
            'lesson_minutes' => 'integer',
            'lessons_placed' => 'integer',
            'lessons_required' => 'integer',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** الحصص التي كتبتها هذه المحاولة — إعادة التوليد تحذف هذه وحدها. */
    public function slots(): HasMany
    {
        return $this->hasMany(ScheduleSlot::class, 'timetable_run_id');
    }

    public function scopeOfSchool(Builder $query, int $schoolId): Builder
    {
        return $query->where('school_id', $schoolId);
    }

    /** نجح التوليد: كل حصّة مطلوبة وُضعت، وبلا تعارض. */
    public function succeeded(): bool
    {
        return $this->status === TimetableRunStatus::Generated;
    }
}
