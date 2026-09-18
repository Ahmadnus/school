<?php

namespace App\Enums;

enum Weekday: int
{
    case Sunday = 0;
    case Monday = 1;
    case Tuesday = 2;
    case Wednesday = 3;
    case Thursday = 4;
    case Friday = 5;
    case Saturday = 6;

    public function label(): string
    {
        return __('weekdays.'.$this->name);
    }

    /**
     * أيّام الدوام في شريط الجدول.
     *
     * السبت يوم دوام في معاهد كثيرة، والجمعة وحدها عطلة مطّردة.
     * الترتيب من السبت لأنّه أوّل أيام الأسبوع الدراسي هنا.
     */
    public static function schoolWeek(): array
    {
        return [
            self::Saturday,
            self::Sunday,
            self::Monday,
            self::Tuesday,
            self::Wednesday,
            self::Thursday,
        ];
    }
}
