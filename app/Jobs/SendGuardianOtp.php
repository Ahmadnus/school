<?php

namespace App\Jobs;

use App\Models\Guardian;
use App\Services\WhatsAppSender;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * يوصل رمز الدخول إلى وليّ الأمر على واتساب.
 *
 * في الطابور لا في الطلب: لو أُرسل داخل الطلب لصار زمن الردّ على رقمٍ
 * مسجَّل أطول منه على رقمٍ غريب، فيكشف التوقيتُ ما يخفيه الردّ المتطابق.
 *
 * والحمولة مشفّرة: الرمز يبيت في جدول `jobs` حتى يلتقطه العامل.
 */
class SendGuardianOtp implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;

    public function __construct(
        private readonly Guardian $guardian,
        private readonly string $code,
    ) {}

    public function handle(): void
    {
        $countryCode = $this->guardian->school?->phone_country_code ?? '963';

        WhatsAppSender::send(
            $this->guardian->phone,
            $countryCode,
            __('messages.guardian_auth.whatsapp_code', ['code' => $this->code]),
        );
    }
}
