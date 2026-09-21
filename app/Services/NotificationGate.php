<?php

namespace App\Services;

use App\Enums\NotificationApp;
use App\Jobs\DeliverNotification;
use App\Models\Notification;
use App\Models\SchoolNotificationSetting;
use App\Models\User;
use App\Models\UserNotificationSetting;

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

        // التسليم يخرج من الطلب: البثّ والدفع نداءان شبكيّان لكل مستلم،
        // وإبقاؤهما هنا يجعل المعلّم ينتظرهما وهو واقف أمام صفّه.
        //
        // `afterResponse` مقصودة بدل الطابور: تعمل بلا عامل طوابير يعمل في
        // الخلفية، فتصحّ على Railway وعلى استضافةٍ مشتركة سواء. ومن ملك
        // عاملاً حقيقيّاً لاحقاً يبدّلها بـ`dispatch` في سطر واحد.
        //
        // وفي الأوامر (التذكير اليومي مثلاً) لا استجابة تُنتظَر، فتُنفَّذ
        // في حينها، وإلاّ خرج الأمر قبل أن يُرسَل شيء.
        $delivery = new DeliverNotification($notification, $user);

        if (app()->runningInConsole()) {
            $delivery->handle();

            return $notification;
        }

        dispatch($delivery)->afterResponse();

        return $notification;
    }
}
