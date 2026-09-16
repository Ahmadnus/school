<?php

namespace App\Services;

use App\Models\BehaviorRecord;
use App\Models\Notification;

/**
 * What a notification points at, beyond its raw ref_id — so the apps can
 * deep-link (a behaviour record opens the student's profile, not a record).
 *
 * Resolved on demand and cached per request; the notification list is a
 * page of 20, so a lookup per behaviour row is cheap and avoids a column.
 */
class NotificationTarget
{
    /** @var array<int, int|null> */
    private static array $cache = [];

    public static function studentIdFor(Notification $notification): ?int
    {
        if ($notification->ref_id === null) {
            return null;
        }

        return self::$cache[$notification->id] ??= match ($notification->type) {
            'behavior_record' => BehaviorRecord::query()->whereKey($notification->ref_id)->value('student_id'),
            default => null,
        };
    }
}
