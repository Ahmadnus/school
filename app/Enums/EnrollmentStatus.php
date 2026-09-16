<?php

namespace App\Enums;

enum EnrollmentStatus: string
{
    case Active = 'active';
    case Transferred = 'transferred';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return __('enrollment_statuses.'.$this->value);
    }
}
