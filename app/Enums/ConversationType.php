<?php

namespace App\Enums;

enum ConversationType: string
{
    case Guardians = 'guardians';
    case Staff = 'staff';

    public function label(): string
    {
        return __('conversation_types.'.$this->value);
    }
}
