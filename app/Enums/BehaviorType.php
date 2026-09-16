<?php

namespace App\Enums;

enum BehaviorType: string
{
    case Positive = 'positive';
    case Negative = 'negative';
    case Warning = 'warning';
    case Incident = 'incident';

    public function label(): string
    {
        return __('behavior_types.'.$this->value);
    }
}
