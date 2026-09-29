<?php

namespace App\Enums;

/**
 * موضوع الشكوى حين لا تكون على شخص.
 *
 * التصنيف مقصود ومحدود: قائمةٌ يختار منها وليّ الأمر بضغطة تصل الإدارة
 * مصنَّفةً جاهزةً للفرز، بدل نصٍّ حرّ يُقرأ واحداً واحداً ليُعرف بابه.
 *
 * و«أخرى» ليست زينة: تصنيفٌ بلا مَخرج يدفع الناس إلى اختيار الباب الخطأ،
 * فتصل الشكوى إلى من لا يملك حلّها ويضيع وقتٌ في تحويلها.
 */
enum ComplaintCategory: string
{
    case Fees = 'fees';
    case Facilities = 'facilities';
    case Canteen = 'canteen';
    case Other = 'other';

    public function label(): string
    {
        return __('complaint_categories.'.$this->value);
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
