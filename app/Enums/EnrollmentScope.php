<?php

namespace App\Enums;

enum EnrollmentScope: string
{
    case FullYear = 'full_year';
    case FirstTerm = 'first_term';
    case SecondTerm = 'second_term';

    public function label(): string
    {
        return __('enrollment_scopes.'.$this->value);
    }
}
