<?php

namespace App\Enums;

enum ConversationType: string
{
    case Guardians = 'guardians';
    case Staff = 'staff';

    /** شكوى وليّ أمر — إلى الإدارة وحدها، ولو كانت على أستاذ. */
    case Complaints = 'complaints';

    public function label(): string
    {
        return __('conversation_types.'.$this->value);
    }
}
