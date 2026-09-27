<?php

namespace App\Models;

use App\Enums\Weekday;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مدًى واحد من تفرّغ أستاذ في يوم.
 *
 * الشاشة تُؤشّر على شبكة نصف ساعة، والمتّصل منها يُخزَّن مدًى واحداً. ونصف
 * الساعة **دقّة التأشير** لا طول الحصّة: مدًى ٩٠ دقيقة يقبل حصّةً واحدة من
 * ستّين دقيقة (بابتداءين ممكنين)، لا ثلاث حصص من نصف ساعة.
 */
class TeacherAvailability extends Model
{
    use HasFactory;

    protected $table = 'teacher_availability';

    /** دقّة التأشير في الشاشة — نصف ساعة. ليست طول الحصّة. */
    public const SLOT_MINUTES = 30;

    /** أوّل ما يُعرَض في الشبكة وآخره — ٠٨:٠٠ ← ١٨:٠٠. */
    public const GRID_START = '08:00';

    public const GRID_END = '18:00';

    protected $fillable = [
        'staff_id',
        'day_of_week',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => Weekday::class,
        ];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function scopeOfSchool(Builder $query, int $schoolId): Builder
    {
        return $query->whereHas('teacher', fn (Builder $q) => $q->where('school_id', $schoolId));
    }

    public function startMinute(): int
    {
        return SchoolDayHours::toMinutes($this->starts_at);
    }

    public function endMinute(): int
    {
        return SchoolDayHours::toMinutes($this->ends_at);
    }

    /** دقائق التفرّغ في هذا المدى — سعة الأستاذ تُقاس بها. */
    public function minutes(): int
    {
        return max(0, $this->endMinute() - $this->startMinute());
    }
}
