<?php

namespace App\Services;

use App\Enums\NotificationApp;
use App\Events\NotificationCreated;
use App\Jobs\DeliverNotification;
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

        // التسليم يخرج من الطلب: البثّ والدفع نداءان شبكيّان لكل مستلم،
        // وإبقاؤهما هنا يجعل المعلّم ينتظرهما وهو واقف أمام صفّه.
        //
        // إلى الطابور: تخرج المهمّة من الطلب فتتحرّر عملية PHP في حينها.
        //
        // كانت `afterResponse` لأنّه لا عامل طوابير: تُنفَّذ بعد ردّ الخادم
        // لكن **داخل العملية نفسها**، فتبقى محجوزة حتى ينتهي الإرسال. وكل
        // جهاز نداءٌ منفصل إلى Google بتسعةٍ وثمانين جزءاً من الألف — أي أنّ
        // إشعاراً لثلاثمئة وليّ أمر يحجز عمليةً ثلاثين ثانية. وعدد العمليات
        // المتزامنة هو أندر ما تملكه الاستضافة المشتركة.
        //
        // وفرعُ الأوامر سقط معها: كان لأنّ `afterResponse` لا تجد استجابةً
        // تنتظرها في أمرٍ سطريّ، أمّا الطابور فيعمل في الموضعين سواء.
        //
        // والفشل لم يعد يضيع: مهمّةٌ تعثّرت تُعاد، وما سقط نهائياً يُحفظ في
        // `failed_jobs` فيُرى ويُعاد تشغيله — وكان يُبتلع في السجلّ.
        //
        // **إلّا البثّ اللحظي**: Reverb على الخادم نفسه، فنداؤه أجزاءٌ من
        // الألف ولا يحجز شيئاً — فيخرج هنا فوراً، ولا ينتظر دوره في الطابور
        // وراء دفع Google. والدفع وحده يبقى في الطابور.
        try {
            NotificationCreated::dispatch($notification);
        } catch (\Throwable $e) {
            Log::warning('Realtime broadcast failed: '.$e->getMessage(), [
                'notification' => $notification->id,
            ]);
        }

        dispatch(new DeliverNotification($notification, $user));

        return $notification;
    }
}
