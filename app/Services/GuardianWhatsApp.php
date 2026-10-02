<?php

namespace App\Services;

use App\Enums\NotificationApp;
use App\Jobs\SendWhatsAppNotice;
use App\Models\Guardian;
use App\Models\SchoolNotificationSetting;
use App\Models\User;
use App\Models\UserNotificationSetting;

/**
 * يوصل حدثاً عن الطالب إلى وليّ أمره على واتساب، إلى جانب إشعار التطبيق.
 *
 * يصل حتى إلى وليّ أمرٍ لم يفتح التطبيق بعد — وهو أكثر من يحتاجه: رقمه
 * مسجَّل عند المدرسة، وحسابه لا يُنشأ إلّا عند أوّل دخول.
 *
 * **لا يُرسل كلّ شيء.** الرقم المربوط بالبوابة رقمٌ حقيقي، وواتساب يحظر
 * الأرقام التي تكثر من الرسائل. فالمفاتيح المرسَلة قائمةٌ صريحة في
 * `services.whatsapp.notify_keys`، والرسائل تخرج من طابورٍ خاصّ بفاصلٍ بينها.
 *
 * ويحترم المفتاحين كما يحترمهما الإشعار: مفتاح المدرسة دائماً، ومفتاح
 * وليّ الأمر إن كان له حساب.
 */
class GuardianWhatsApp
{
    public static function send(Guardian $guardian, string $key, string $title, ?string $body = null): void
    {
        if (! self::wanted($key) || blank($guardian->phone)) {
            return;
        }

        if (! self::schoolAllows($guardian->school_id, $key)) {
            return;
        }

        if ($guardian->user_id !== null && ! self::userAllows($guardian->user_id, $key)) {
            return;
        }

        $text = '*'.$title.'*'.($body ? "\n".$body : '');

        SendWhatsAppNotice::dispatch(
            $guardian->phone,
            $guardian->school?->phone_country_code ?? '963',
            $text,
        );
    }

    /** The guardian behind an app account, for notifications sent to a user. */
    public static function sendToUser(User $user, string $key, string $title, ?string $body = null): void
    {
        if (! self::wanted($key)) {
            return;
        }

        $guardian = Guardian::query()->where('user_id', $user->id)->with('school')->first();

        if ($guardian !== null) {
            self::send($guardian, $key, $title, $body);
        }
    }

    private static function wanted(string $key): bool
    {
        return WhatsAppSender::isConfigured()
            && in_array($key, config('services.whatsapp.notify_keys', []), true);
    }

    private static function schoolAllows(int $schoolId, string $key): bool
    {
        $setting = SchoolNotificationSetting::query()
            ->ofSchool($schoolId)
            ->where('app', NotificationApp::Guardian)
            ->where('key', $key)
            ->first();

        return $setting?->is_enabled ?? true;
    }

    private static function userAllows(int $userId, string $key): bool
    {
        return UserNotificationSetting::query()
            ->where('user_id', $userId)
            ->where('key', $key)
            ->first()?->is_enabled ?? true;
    }
}
