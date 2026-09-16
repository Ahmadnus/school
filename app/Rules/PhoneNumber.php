<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A phone number any school can use: optional leading `+`, 7–15 digits,
 * spaces/dashes tolerated on input. Replaces the old `09xxxxxxxx` regex that
 * hard-wired one country's mobile format into every form.
 */
class PhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $digits = preg_replace('/[\s\-()]/', '', (string) $value);

        if (! preg_match('/^\+?[0-9]{7,15}$/', $digits)) {
            $fail(__('validation.phone_number'));
        }
    }
}
