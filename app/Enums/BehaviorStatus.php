<?php

namespace App\Enums;

enum BehaviorStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';

    public function label(): string
    {
        return __('behavior_statuses.'.$this->value);
    }
}
