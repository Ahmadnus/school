<?php

namespace App\Jobs;

use App\Services\WhatsAppSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * رسالة واتساب واحدة لوليّ أمر — على طابور `whatsapp` وحده.
 *
 * طابورٌ مستقلّ له عاملٌ واحد، فتخرج الرسائل واحدةً بعد واحدة بفاصلٍ بينها:
 * دفعةُ ثلاثين رسالة في ثانية هي ما يجعل واتساب يحظر الرقم. ولا تؤخّر هذه
 * الفواصل إشعارات التطبيق ولا رموز الدخول، فتلك على الطابور الافتراضي.
 */
class SendWhatsAppNotice implements ShouldQueue
{
    use Queueable;

    /** Seconds between two messages from the gateway number. */
    private const SPACING = 3;

    public function __construct(
        private readonly string $phone,
        private readonly string $countryCode,
        private readonly string $text,
    ) {
        $this->onQueue('whatsapp');
    }

    public function handle(): void
    {
        WhatsAppSender::send($this->phone, $this->countryCode, $this->text);

        if (! app()->runningUnitTests()) {
            sleep(self::SPACING);
        }
    }
}
