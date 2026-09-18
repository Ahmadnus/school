<?php

namespace App\Models;

use App\Enums\Weekday;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * دوام شعبة في يوم، ومنه تُشتقّ أوقات حصصه.
 */
class SectionDayHours extends Model
{
    use HasFactory;

    protected $table = 'section_day_hours';

    protected $fillable = [
        'section_id',
        'day_of_week',
        'starts_at',
        'ends_at',
        'period_minutes',
        'break_minutes',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => Weekday::class,
            'period_minutes' => 'integer',
            'break_minutes' => 'integer',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * الحصص المشتقّة من هذا الدوام: رقم ووقت بدء ووقت انتهاء.
     *
     * تتوقّف عند تجاوز نهاية الدوام، فلا تُخترع حصّة بعد انصراف الطلاب.
     * والفاصل يُحسب **بين** الحصص لا بعد آخرها.
     *
     * @return array<int, array{period_number: int, starts_at: string, ends_at: string}>
     */
    public function periods(): array
    {
        $start = $this->minutesOf($this->starts_at);
        $end = $this->minutesOf($this->ends_at);
        $length = max(1, $this->period_minutes);
        $break = max(0, $this->break_minutes);

        $periods = [];
        $cursor = $start;
        $number = 1;

        // حدّ أعلى يحرس من دوام مقلوب أو طول حصّة صفريّ يولّد حلقة لا تنتهي.
        while ($cursor + $length <= $end && $number <= 20) {
            $periods[] = [
                'period_number' => $number,
                'starts_at' => self::format($cursor),
                'ends_at' => self::format($cursor + $length),
            ];

            $cursor += $length + $break;
            $number++;
        }

        return $periods;
    }

    private function minutesOf(mixed $time): int
    {
        [$h, $m] = array_map('intval', explode(':', substr((string) $time, 0, 5)));

        return $h * 60 + $m;
    }

    private static function format(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
    }
}
