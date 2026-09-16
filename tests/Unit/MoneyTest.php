<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

/** الأساس الحسابي: إن اختلّ هنا اختلّ كل رقم في النظام. */
class MoneyTest extends TestCase
{
    public function test_parses_decimal_input_without_rounding_drift(): void
    {
        $this->assertSame(125075, Money::fromDecimal('1250.75')->minor);
        $this->assertSame(100, Money::fromDecimal('1')->minor);
        $this->assertSame(1, Money::fromDecimal('0.01')->minor);
        // القيمة التي تُخطئ فيها (int) round($v * 100).
        $this->assertSame(100, Money::fromDecimal('1.005')->minor);
        $this->assertSame(-2550, Money::fromDecimal('-25.50')->minor);
    }

    public function test_round_trips_to_decimal(): void
    {
        $this->assertSame('1250.75', Money::fromDecimal('1250.75')->toDecimal());
        $this->assertSame('0.05', Money::fromMinor(5)->toDecimal());
        $this->assertSame('0.00', Money::zero()->toDecimal());
        $this->assertSame('-3.40', Money::fromMinor(-340)->toDecimal());
    }

    public function test_repeated_addition_does_not_drift(): void
    {
        $total = Money::zero();

        for ($i = 0; $i < 1000; $i++) {
            $total = $total->plus(Money::fromDecimal('0.10'));
        }

        $this->assertSame('100.00', $total->toDecimal());
    }

    public function test_split_keeps_the_sum_exact(): void
    {
        $parts = Money::fromDecimal('100.00')->split(3);

        $this->assertSame(['33.34', '33.33', '33.33'], array_map(fn ($p) => $p->toDecimal(), $parts));
        $this->assertSame('100.00', Money::sum($parts)->toDecimal());
    }

    public function test_distribute_scales_instalments_without_losing_a_fils(): void
    {
        // خطة 1000 بخصم 333.33 → صافي 666.67 على ثلاثة أقساط متساوية.
        $shares = Money::distribute(Money::fromDecimal('666.67'), [33333, 33333, 33334]);

        $this->assertSame('666.67', Money::sum($shares)->toDecimal());
    }

    public function test_distribute_handles_uneven_weights(): void
    {
        $shares = Money::distribute(Money::fromDecimal('900.00'), [50000, 30000, 20000]);

        $this->assertSame(['450.00', '270.00', '180.00'], array_map(fn ($s) => $s->toDecimal(), $shares));
        $this->assertSame('900.00', Money::sum($shares)->toDecimal());
    }
}
