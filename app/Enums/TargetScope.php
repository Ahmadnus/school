<?php

namespace App\Enums;

enum TargetScope: string
{
    case School = 'school';
    case Grade = 'grade';
    case Section = 'section';
    case Student = 'student';
    case Subject = 'subject';

    public function label(): string
    {
        return __('target_scopes.'.$this->value);
    }
}
