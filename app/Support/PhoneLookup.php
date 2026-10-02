<?php

namespace App\Support;

use App\Models\School;

/**
 * الصيغ التي قد يكتب بها إنسانٌ رقماً واحداً.
 *
 * المدرسة تسجّل الرقم محلّياً `0944123456`، والأب يكتبه كما يحفظه:
 * `+963 944 123 456` أو `00963944123456` أو `944123456`. والبحث كان حرفيّاً،
 * فيُقال له «أُرسل الرمز» ولا يصله شيء أبداً — والردّ متطابق عمداً للرقم
 * المعروف والغريب، فلا يعرف حتى أنّ رقمه لم يُطابَق.
 *
 * فتُولَّد الصيغ المحتملة ويُبحث بها كلّها؛ والمطابقة تبقى تامّة على كل صيغة
 * فلا يلتقط رقمٌ رقماً آخر.
 */
final class PhoneLookup
{
    /** @return list<string> */
    public static function candidates(string $input): array
    {
        $digits = preg_replace('/[\s\-().\/]/', '', $input);

        // بريدٌ إلكترونيّ أو أيّ نصّ ليس رقماً: لا صيغ أخرى له.
        if (! preg_match('/^\+?\d{6,15}$/', $digits)) {
            return [$input];
        }

        $candidates = [$input, $digits];

        $international = match (true) {
            str_starts_with($digits, '+') => substr($digits, 1),
            str_starts_with($digits, '00') => substr($digits, 2),
            default => null,
        };

        foreach (self::countryCodes() as $code) {
            $national = match (true) {
                $international !== null && str_starts_with($international, $code) => substr($international, strlen($code)),
                // «963944…» بلا علامة دولية.
                $international === null && str_starts_with($digits, $code) && strlen($digits) > strlen($code) + 7 => substr($digits, strlen($code)),
                // «944123456» بلا الصفر.
                $international === null && ! str_starts_with($digits, '0') => $digits,
                default => null,
            };

            if ($national !== null && $national !== '') {
                $national = ltrim($national, '0');
                $candidates[] = '0'.$national;
                $candidates[] = '+'.$code.$national;
            }
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    /** @return list<string> */
    private static function countryCodes(): array
    {
        return School::query()
            ->distinct()
            ->pluck('phone_country_code')
            ->filter()
            ->push('963')
            ->unique()
            ->values()
            ->all();
    }
}
