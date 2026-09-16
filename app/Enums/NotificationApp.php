<?php

namespace App\Enums;

enum NotificationApp: string
{
    case Staff = 'staff';
    case Guardian = 'guardian';

    public function label(): string
    {
        return __('notification_apps.'.$this->value);
    }
}
