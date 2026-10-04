<?php

namespace App\Services;

use App\Enums\NotificationApp;
use App\Jobs\SendWhatsAppNotice;
use App\Models\Guardian;
use App\Models\SchoolNotificationSetting;
use App\Models\Student;
use App\Models\UserNotificationSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * **الغياب على واتساب** — إلى جانب إشعار التطبيق، لا بدلاً منه.
 *
 * الغياب وحده: الرقم المربوط بالبوابة رقمٌ حقيقي، وواتساب يحظر الأرقام التي
 * تكثر من الرسائل. ويصل حتى وليّ أمرٍ لم يفتح التطبيق بعد — رقمه مسجَّل عند
 * المدرسة، وحسابه لا يُنشأ إلّا عند أوّل دخول.
 *
 * ويحترم ما يحترمه الإشعار: مفتاح `attendance_absence` عند المدرسة دائماً،
 * وعند وليّ الأمر إن كان له حساب. ورسالةٌ واحدة لكل ابنٍ في اليوم: إعادة
 * إرسال التفقّد بعد تصحيحٍ لا تُعيد الرسالة.
 *
 * `services.whatsapp.only_to` وضعُ تجربة: إن ضُبط لا تخرج رسالة إلّا إلى
 * الأرقام المذكورة فيه.
 */
class GuardianWhatsApp
{
    public const KEY = 'attendance_absence';

    public static function absence(Guardian $guardian, Student $student, string $date, string $sectionLabel): void
    {
        if (! config('services.whatsapp.absence') || ! WhatsAppSender::isConfigured() || blank($guardian->phone)) {
            return;
        }

        $countryCode = $guardian->school?->phone_country_code ?? $student->school?->phone_country_code ?? '963';

        if (! self::inTrialList($guardian->phone, $countryCode)) {
            return;
        }

        if (! self::schoolAllows($student->school_id) || ! self::userAllows($guardian->user_id)) {
            return;
        }

        // مرّة واحدة لكل وليّ أمر وابن ويوم.
        if (! Cache::add("wa-absence:{$guardian->id}:{$student->id}:{$date}", true, now()->addDays(2))) {
            return;
        }

        // بلغة المدرسة لا بلغة الأستاذ الذي سجّل التفقّد.
        $text = __('messages.whatsapp.absence', [
            'name' => $student->full_name,
            'date' => $date,
            'section' => $sectionLabel,
            'school' => $student->school?->name ?? '',
        ], config('app.locale'));

        SendWhatsAppNotice::dispatch($guardian->phone, $countryCode, $text)
            ->delay(self::nextSlot());
    }

    /**
     * موعد الرسالة التالية: بعد سابقتها بـ`spacing` ثوانٍ على الأقل، فتخرج
     * رسائل صفٍّ كامل متباعدةً بدل دفعةٍ واحدة يحظر بها واتساب الرقم.
     */
    private static function nextSlot(): Carbon
    {
        $spacing = max(0, (int) config('services.whatsapp.spacing', 3));

        return Cache::lock('wa-slot', 5)->block(5, function () use ($spacing) {
            $at = max(now()->getTimestamp(), (int) Cache::get('wa-next-at', 0));
            Cache::put('wa-next-at', $at + $spacing, now()->addHour());

            return Carbon::createFromTimestamp($at);
        });
    }

    private static function inTrialList(string $phone, string $countryCode): bool
    {
        $only = config('services.whatsapp.only_to', []);
        if ($only === []) {
            return true;
        }

        $target = WhatsAppSender::chatIdFor($phone, $countryCode);
        foreach ($only as $allowed) {
            if (WhatsAppSender::chatIdFor($allowed, $countryCode) === $target) {
                return true;
            }
        }

        return false;
    }

    private static function schoolAllows(int $schoolId): bool
    {
        $setting = SchoolNotificationSetting::query()
            ->ofSchool($schoolId)
            ->where('app', NotificationApp::Guardian)
            ->where('key', self::KEY)
            ->first();

        return $setting?->is_enabled ?? true;
    }

    private static function userAllows(?int $userId): bool
    {
        if ($userId === null) {
            return true;
        }

        return UserNotificationSetting::query()
            ->where('user_id', $userId)
            ->where('key', self::KEY)
            ->first()?->is_enabled ?? true;
    }
}
