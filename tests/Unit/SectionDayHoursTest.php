<?php

namespace Tests\Unit;

use App\Models\SectionDayHours;
use PHPUnit\Framework\TestCase;

/**
 * اشتقاق الحصص من دوام اليوم — هذا ما يوفّر كتابة الأوقات حصّة حصّة.
 */
class SectionDayHoursTest extends TestCase
{
    private function hours(string $from, string $to, int $length = 45, int $break = 0): SectionDayHours
    {
        $model = new SectionDayHours;
        $model->starts_at = $from;
        $model->ends_at = $to;
        $model->period_minutes = $length;
        $model->break_minutes = $break;

        return $model;
    }

    public function test_a_day_splits_into_periods_of_the_given_length(): void
    {
        // ١١:٣٠ ← ٥:٠٠ بحصص ٤٥ دقيقة = سبع حصص وربع ساعة متبقّية.
        $periods = $this->hours('11:30', '17:00')->periods();

        $this->assertCount(7, $periods);
        $this->assertSame('11:30', $periods[0]['starts_at']);
        $this->assertSame('12:15', $periods[0]['ends_at']);
        $this->assertSame(1, $periods[0]['period_number']);
    }

    public function test_no_period_is_invented_after_the_day_ends(): void
    {
        // آخر حصّة تنتهي قبل النهاية أو عندها، لا بعدها.
        foreach ($this->hours('11:30', '17:00')->periods() as $period) {
            $this->assertLessThanOrEqual('17:00', $period['ends_at']);
        }
    }

    public function test_breaks_sit_between_periods_not_after_the_last(): void
    {
        // ٩:٠٠ ← ١٢:٠٠، حصّة ٤٠ وفاصل ١٠.
        // الرابعة ستبدأ ١١:٣٠ وتنتهي ١٢:١٠ — بعد الانصراف، فلا تُولَّد.
        $periods = $this->hours('09:00', '12:00', 40, 10)->periods();

        $this->assertCount(3, $periods);
        $this->assertSame('09:50', $periods[1]['starts_at']);
        $this->assertSame('10:40', $periods[2]['starts_at']);
        $this->assertSame('11:20', $periods[2]['ends_at']);
    }

    public function test_the_saturday_morning_shift_starts_at_nine(): void
    {
        $periods = $this->hours('09:00', '13:00')->periods();

        $this->assertSame('09:00', $periods[0]['starts_at']);
        $this->assertCount(5, $periods);
    }

    public function test_a_backwards_day_yields_nothing_instead_of_looping(): void
    {
        // خطأ إدخال لا يجوز أن يعلّق الخادم.
        $this->assertSame([], $this->hours('17:00', '11:30')->periods());
    }

    public function test_a_zero_length_period_cannot_spin_forever(): void
    {
        $this->assertLessThanOrEqual(20, count($this->hours('08:00', '18:00', 0)->periods()));
    }
}
