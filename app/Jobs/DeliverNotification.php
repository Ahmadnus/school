<?php

namespace App\Jobs;

use App\Events\NotificationCreated;
use App\Models\Notification;
use App\Models\User;
use App\Services\FcmSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * يوصل إشعاراً واحداً إلى قناتيه: البثّ اللحظي ودفع الهاتف.
 *
 * كان هذا يجري **داخل الطلب**: تسجيل حضور شعبة من ثلاثين طالباً يعني ستّين
 * نداءً شبكيّاً خارجيّاً قبل أن يردّ الخادم على المعلّم — ينتظر وهو واقف
 * أمام الصفّ. وعلى استضافةٍ مشتركة سقفها ثلاثون ثانية يُقطع الطلب في
 * منتصفه، فيصل الإشعار إلى نصف الأهالي ولا يصل إلى نصفهم، بلا أثرٍ يدلّ.
 *
 * التسليم هنا **أفضل جهد** كما كان: سجلّ الإشعار في قاعدة البيانات هو مصدر
 * الحقيقة، وفشل البثّ أو الدفع يُسجَّل ولا يُسقط شيئاً.
 */
class DeliverNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Notification $notification,
        private readonly User $user,
    ) {}

    public function handle(): void
    {
        try {
            NotificationCreated::dispatch($this->notification);
        } catch (\Throwable $e) {
            Log::warning('Realtime broadcast failed: '.$e->getMessage(), [
                'notification' => $this->notification->id,
            ]);
        }

        try {
            FcmSender::send($this->user, $this->notification);
        } catch (\Throwable $e) {
            Log::warning('FCM push failed: '.$e->getMessage(), [
                'notification' => $this->notification->id,
            ]);
        }
    }
}
