<?php

namespace App\Enums;

enum ConversationStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return __('conversation_statuses.'.$this->value);
    }
}
