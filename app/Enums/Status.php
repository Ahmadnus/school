<?php

namespace App\Enums;

enum Status: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return __('statuses.'.$this->value);
    }
}
