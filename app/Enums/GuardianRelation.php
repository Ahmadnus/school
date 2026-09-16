<?php

namespace App\Enums;

enum GuardianRelation: string
{
    case Father = 'father';
    case Mother = 'mother';
    case Custodian = 'custodian';

    public function label(): string
    {
        return __('guardian_relations.'.$this->value);
    }
}
