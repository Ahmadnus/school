<?php

namespace App\Services;

use App\Enums\NotificationApp;
use App\Events\NotificationCreated;
use App\Models\Notification;
use App\Models\SchoolNotificationSetting;
use App\Models\User;
use App\Models\UserNotificationSetting;
use Illuminate\Support\Facades\Log;

/**
 * A notification is delivered only when it is enabled at BOTH levels
 * (decision 8-a): the school switch for the app, and the user's own switch.
 *
 * The in-app record is what the notifications screen reads; it is also
 * broadcast over Reverb on the user's private channel, so an open app raises a
 * system notification at once. Delivery to a closed app (FCM/APNs) stays out
 * of scope.
 */
class NotificationGate
{
    public static function allows(User $user, string $key, NotificationApp $app = NotificationApp::Staff): bool
    {
        $school = SchoolNotificationSetting::query()
            ->ofSchool($user->school_id)
            ->where('app', $app)
            ->where('key', $key)
            ->first();

        // An unknown key is treated as enabled at the school level.
        if ($school && ! $school->is_enabled) {
            return false;
        }

        $personal = UserNotificationSetting::query()
            ->where('user_id', $user->id)
            ->where('key', $key)
            ->first();

        return $personal?->is_enabled ?? true;
    }

    /** Records the notification when both switches allow it; returns null otherwise. */
    public static function notify(
        User $user,
        string $key,
        string $title,
        ?string $body = null,
        ?int $refId = null,
        NotificationApp $app = NotificationApp::Staff,
    ): ?Notification {
        if (! self::allows($user, $key, $app)) {
            return null;
        }

        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => $key,
            'title' => $title,
            'body' => $body,
            'ref_id' => $refId,
        ]);

        // Two delivery paths, on purpose:
        //  - Reverb: instant, for an app that is open right now.
        //  - FCM: reaches the device even when the app is closed.
        //
        // Both are best-effort. The in-app record above is the source of
        // truth; a Reverb server that is down (or an FCM hiccup) must never
        // turn a successful action into a 500, nor stop the other path —
        // before this guard an unreachable Reverb aborted the whole fan-out
        // and no guardian ever got the push.
        try {
            NotificationCreated::dispatch($notification);
        } catch (\Throwable $e) {
            Log::warning('Realtime broadcast failed: '.$e->getMessage(), ['notification' => $notification->id]);
        }

        try {
            FcmSender::send($user, $notification);
        } catch (\Throwable $e) {
            Log::warning('FCM push failed: '.$e->getMessage(), ['notification' => $notification->id]);
        }

        return $notification;
    }
}
