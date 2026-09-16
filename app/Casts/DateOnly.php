<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A date-only column: reads as a Carbon, writes as Y-m-d.
 *
 * Laravel's built-in "date" cast still stores "Y-m-d H:i:s", so a lookup by
 * "2026-09-05" never matches the stored value — on attendance_records, whose
 * unique key is (student_id, date), that turns an intended update into a
 * constraint violation.
 *
 * @implements CastsAttributes<Carbon|null, string|null>
 */
class DateOnly implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value)->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toDateString();
    }
}
