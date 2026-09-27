<?php

namespace App\Enums;

enum TimetableRunStatus: string
{
    /** فُحصت الجدوى ولم تُكتب حصّة بعد. */
    case Analyzed = 'analyzed';

    /** نجحت، وحصصها مكتوبة في `schedule_slots`. */
    case Generated = 'generated';

    /** ثبت أنّ الجدول مستحيل بهذه القيود، والسبب في `conflicts`. */
    case Infeasible = 'infeasible';

    public function label(): string
    {
        return __('timetable.status.'.$this->value);
    }
}
