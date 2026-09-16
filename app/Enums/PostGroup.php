<?php

namespace App\Enums;

/** The colour groups of the type picker grid. */
enum PostGroup: string
{
    case Academic = 'academic';
    case Events = 'events';
    case FollowUp = 'follow_up';
    case Alert = 'alert';

    public function label(): string
    {
        return __('post_groups.'.$this->value);
    }
}
