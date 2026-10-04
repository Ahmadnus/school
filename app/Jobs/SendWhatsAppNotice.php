<?php

namespace App\Jobs;

use App\Services\WhatsAppSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * رسالة واتساب واحدة لوليّ أمر.
 *
 * على الطابور الافتراضي (الوحيد الذي يعمل له عاملٌ على الخادم)، والتباعد بين
 * الرسائل يأتي من موعد التأخير الذي يحجزه {@see \App\Services\GuardianWhatsApp}
 * لا من `sleep` يحجز العامل عن رموز الدخول والإشعارات.
 */
class SendWhatsAppNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly string $phone,
        public readonly string $countryCode,
        public readonly string $text,
    ) {}

    public function handle(): void
    {
        WhatsAppSender::send($this->phone, $this->countryCode, $this->text);
    }
}
