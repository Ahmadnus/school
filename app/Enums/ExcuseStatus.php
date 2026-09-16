<?php

namespace App\Enums;

enum ExcuseStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    public function label(): string
    {
        return __('excuse_statuses.'.$this->value);
    }
}
