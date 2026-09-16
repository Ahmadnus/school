<?php

namespace App\Enums;

enum AttendanceSessionStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';

    public function label(): string
    {
        return __('attendance_session_statuses.'.$this->value);
    }
}
