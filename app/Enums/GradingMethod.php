<?php

namespace App\Enums;

enum GradingMethod: string
{
    case Numeric = 'numeric';
    case Descriptive = 'descriptive';

    public function label(): string
    {
        return __('grading_methods.'.$this->value);
    }
}
