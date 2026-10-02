<?php

namespace App\Jobs;

use App\Services\WhatsAppSender;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * يوصل رمز الدخول على واتساب — لوليّ الأمر وللكادر سواءً.
 *
 * في الطابور لا في الطلب: لو أُرسل داخل الطلب لصار زمن الردّ على رقمٍ
 * مسجَّل أطول منه على رقمٍ غريب، فيكشف التوقيتُ ما يخفيه الردّ المتطابق.
 *
 * والحمولة مشفّرة: الرمز يبيت في جدول `jobs` حتى يلتقطه العامل.
 */
class SendLoginCode implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;

    public function __construct(
        private readonly string $phone,
        private readonly string $countryCode,
        private readonly string $code,
        // الرسالة تُفتتح باسم المدرسة: رمزٌ من رقمٍ لا يعرفه المستلم يُتجاهَل
        // ما لم يقل من أين جاء.
        private readonly string $schoolName = '',
    ) {}

    public function handle(): void
    {
        WhatsAppSender::send(
            $this->phone,
            $this->countryCode,
            __('messages.guardian_auth.whatsapp_code', [
                'code' => $this->code,
                'school' => $this->schoolName !== '' ? $this->schoolName : config('app.name'),
            ]),
        );
    }
}
