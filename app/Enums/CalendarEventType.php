<?php

namespace App\Enums;

/**
 * The calendar has no table of its own (decision 10-a); these are the sources
 * the union view draws from.
 */
enum CalendarEventType: string
{
    case Period = 'period';
    case Holiday = 'holiday';
    case Installment = 'installment';
    case Post = 'post';
    case Assessment = 'assessment';

    public function label(): string
    {
        return __('calendar_event_types.'.$this->value);
    }
}
