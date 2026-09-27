<?php

namespace App\Services\Timetable;

/**
 * مدًى زمني بالدقائق من منتصف الليل.
 *
 * كل القيود في المولّد مدياتٌ تُقاطَع: دوام المعهد، ودوام الشعبة، وتفرّغ
 * الأستاذ. فجُمعت العمليّة في نوع واحد بدل أن تُكتب المقارنة في كل خدمة —
 * وأخطاء الجدولة كلّها تقريباً أخطاء حدود: `<` مكان `<=`.
 */
final readonly class TimeRange
{
    public function __construct(
        public int $start,
        public int $end,
    ) {}

    public static function of(string $from, string $to): self
    {
        return new self(self::minutes($from), self::minutes($to));
    }

    /** «١١:٣٠» ← ٦٩٠. يقبل «11:30» و«11:30:00» وكائن الوقت سواء. */
    public static function minutes(mixed $time): int
    {
        $text = substr((string) $time, 0, 5);
        [$hours, $mins] = array_map('intval', array_pad(explode(':', $text), 2, 0));

        return $hours * 60 + $mins;
    }

    /** ٦٩٠ ← «١١:٣٠». */
    public static function format(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
    }

    public function minutes_(): int
    {
        return max(0, $this->end - $this->start);
    }

    public function isEmpty(): bool
    {
        return $this->end <= $this->start;
    }

    /**
     * التقاطُع، أو `null` إن لم يتلامسا.
     *
     * تلامسٌ بلا تداخل (ينتهي أحدهما حيث يبدأ الآخر) ليس تقاطُعاً: لا تدخل
     * حصّة في نافذةٍ طولها صفر.
     */
    public function intersect(self $other): ?self
    {
        $start = max($this->start, $other->start);
        $end = min($this->end, $other->end);

        return $end > $start ? new self($start, $end) : null;
    }

    /** هل يحتوي هذا المدى المدى الآخر **بكامله**؟ */
    public function contains(self $other): bool
    {
        return $other->start >= $this->start && $other->end <= $this->end;
    }

    /** هل يتداخل المديان؟ التلامس عند الحدّ ليس تداخلاً. */
    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $this->end > $other->start;
    }

    public function shift(int $start, int $length): self
    {
        return new self($start, $start + $length);
    }

    public function __toString(): string
    {
        return self::format($this->start).'–'.self::format($this->end);
    }
}
