<?php

namespace App\Models;

use App\Enums\Weekday;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * دوام المعهد في يوم — الحدّ الخارجي لكل جدول.
 *
 * لا يقول شيئاً عن طول الحصّة: ذلك `schools.default_lesson_minutes` وما
 * يتجاوزه في `section_day_hours`. هذا الجدول يقول «متى يفتح المعهد» وحده.
 */
class SchoolDayHours extends Model
{
    use HasFactory;

    protected $table = 'school_day_hours';

    protected $fillable = [
        'school_id',
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

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function scopeOfSchool(Builder $query, int $schoolId): Builder
    {
        return $query->where('school_id', $schoolId);
    }

    /** دقيقة البداية من منتصف الليل — وحدة الحساب في المولّد. */
    public function startMinute(): int
    {
        return self::toMinutes($this->starts_at);
    }

    public function endMinute(): int
    {
        return self::toMinutes($this->ends_at);
    }

    /**
     * «١١:٣٠» ← ٦٩٠.
     *
     * الوقت يصل من MySQL نصّاً «11:30:00» ومن SQLite «11:30»، وقد يصل كائن
     * وقت. فالتحويل في مكان واحد بدل أن يُكرَّر في كل خدمة.
     */
    public static function toMinutes(mixed $time): int
    {
        $text = substr((string) $time, 0, 5);
        [$hours, $minutes] = array_map('intval', array_pad(explode(':', $text), 2, 0));

        return $hours * 60 + $minutes;
    }

    /** ٦٩٠ ← «١١:٣٠». */
    public static function toTime(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
    }
}
