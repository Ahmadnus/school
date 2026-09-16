<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * مبلغ مالي محفوظ كعدد صحيح من الفلس (أصغر وحدة).
 *
 * سبب الوجود: كل حساب بـ float يراكم انحراف تقريب — 0.1 + 0.2 ليست 0.3.
 * في نظام أقساط هذا يعني فلساً ضائعاً كل عملية، ثم فرقاً في الدفاتر لا أحد
 * يعرف مصدره. هنا لا يوجد float إطلاقاً: كل جمع وطرح ومقارنة على int، وكل
 * قسمة تمرّ عبر allocate() التي توزّع الباقي بدل أن ترميه.
 */
final class Money
{
    /** عدد الخانات العشرية للعملة. */
    public const SCALE = 2;

    private function __construct(public readonly int $minor) {}

    public static function zero(): self
    {
        return new self(0);
    }

    public static function fromMinor(int $minor): self
    {
        return new self($minor);
    }

    /**
     * تحويل مدخل المستخدم ("1250.75"، 1250.75، 1250) إلى فلس.
     *
     * التحويل نصّي لا حسابي: (int) round($v * 100) تخطئ على قيم مثل 1.005،
     * لذا يُقصّ الجزء العشري من النص مباشرة.
     */
    public static function fromDecimal(int|float|string $value): self
    {
        $text = is_float($value) ? number_format($value, self::SCALE + 4, '.', '') : (string) $value;
        $text = trim($text);

        if (! preg_match('/^(-?)(\d+)(?:\.(\d*))?$/', $text, $m)) {
            throw new InvalidArgumentException("قيمة مالية غير صالحة: {$text}");
        }

        [, $sign, $whole, $fraction] = $m + [3 => ''];

        $fraction = str_pad(substr($fraction, 0, self::SCALE), self::SCALE, '0');
        $minor = (int) $whole * (10 ** self::SCALE) + (int) $fraction;

        return new self($sign === '-' ? -$minor : $minor);
    }

    /** التمثيل الذي يخرج في الـ API: "1250.75". */
    public function toDecimal(): string
    {
        $sign = $this->minor < 0 ? '-' : '';
        $abs = abs($this->minor);
        $unit = 10 ** self::SCALE;

        return $sign.intdiv($abs, $unit).'.'.str_pad((string) ($abs % $unit), self::SCALE, '0', STR_PAD_LEFT);
    }

    public function plus(self $other): self
    {
        return new self($this->minor + $other->minor);
    }

    public function minus(self $other): self
    {
        return new self($this->minor - $other->minor);
    }

    /** لا ينزل تحت الصفر — للمتبقّي المعروض. */
    public function clampToZero(): self
    {
        return new self(max(0, $this->minor));
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor;
    }

    public function greaterThan(self $other): bool
    {
        return $this->minor > $other->minor;
    }

    public function lessThan(self $other): bool
    {
        return $this->minor < $other->minor;
    }

    public function min(self $other): self
    {
        return $this->minor <= $other->minor ? $this : $other;
    }

    /** @param iterable<self> $amounts */
    public static function sum(iterable $amounts): self
    {
        $total = 0;

        foreach ($amounts as $amount) {
            $total += $amount->minor;
        }

        return new self($total);
    }

    /**
     * توزيع المبلغ على أوزان (مثلاً أقساط النوع الأصلية) بحيث يساوي مجموع
     * النواتج المبلغَ تماماً. يُستعمل عند تطبيق خصم: الأقساط تُصغَّر نسبياً
     * ويُوزَّع باقي القسمة فلساً فلساً بطريقة أكبر الكسور، فلا يظهر فرق بين
     * مجموع الأقساط والمبلغ الصافي.
     *
     * @param  array<int, int>  $weights
     * @return array<int, self>
     */
    public static function distribute(self $total, array $weights): array
    {
        $count = count($weights);

        if ($count === 0) {
            return [];
        }

        $weightSum = array_sum($weights);

        if ($weightSum <= 0) {
            return $total->split($count);
        }

        $shares = [];
        $remainders = [];
        $assigned = 0;

        foreach ($weights as $index => $weight) {
            $exact = $total->minor * $weight;
            $share = intdiv($exact, $weightSum);
            $shares[$index] = $share;
            $remainders[$index] = $exact - ($share * $weightSum);
            $assigned += $share;
        }

        arsort($remainders);

        foreach (array_keys($remainders) as $index) {
            if ($assigned >= $total->minor) {
                break;
            }

            $shares[$index]++;
            $assigned++;
        }

        ksort($shares);

        return array_map(static fn (int $minor) => new self($minor), $shares);
    }

    /**
     * تقسيم المبلغ على $parts قسطاً بحيث يبقى المجموع مساوياً للأصل تماماً.
     * الفلوس الباقية من القسمة تُضاف فلساً فلساً على الأقساط الأولى.
     *
     * @return array<int, self>
     */
    public function split(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('عدد الأقساط يجب أن يكون 1 فأكثر.');
        }

        $base = intdiv($this->minor, $parts);
        $remainder = $this->minor - ($base * $parts);

        return array_map(
            fn (int $i) => new self($base + ($i < $remainder ? 1 : 0)),
            range(0, $parts - 1),
        );
    }
}
