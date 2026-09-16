<?php

namespace App\Enums;

enum AttendanceSource: string
{
    case Manual = 'manual';
    case Gate = 'gate';

    public function label(): string
    {
        return __('attendance_sources.'.$this->value);
    }
}
