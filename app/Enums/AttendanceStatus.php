<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case Present = 'present';
    case Late = 'late';
    case Absent = 'absent';

    public function label(): string
    {
        return __('attendance_statuses.'.$this->value);
    }
}
