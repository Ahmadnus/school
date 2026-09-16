<?php

namespace App\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * عمود مالي: يُخزَّن BIGINT بالفلس ويُقرأ Money.
 *
 * الكتابة تقبل Money أو نصاً عشرياً ("1250.75") القادم من الفورم، فلا يحتاج
 * أي كود استدعاء أن يتذكّر الضرب في 100.
 *
 * @implements CastsAttributes<Money, Money|string|int|float>
 */
class MoneyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): Money
    {
        return Money::fromMinor((int) ($value ?? 0));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        return ($value instanceof Money ? $value : Money::fromDecimal($value ?? 0))->minor;
    }
}
