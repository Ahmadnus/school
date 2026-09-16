<?php

namespace App\Enums;

enum FeePlanStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('fee_plan_statuses.'.$this->value);
    }
}
