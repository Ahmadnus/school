<?php

namespace Tests\Feature;

use App\Enums\NotificationApp;
use App\Enums\UserRole;
use App\Jobs\DeliverNotification;
use App\Models\School;
use App\Models\User;
use App\Services\NotificationGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * تسليم الإشعار يخرج من الطلب.
 *
 * كان يجري بعد ردّ الخادم لكن **داخل العملية نفسها**، فتبقى محجوزة حتى ينتهي
 * الإرسال. وكل جهاز نداءٌ منفصل إلى Google بتسعةٍ وثمانين جزءاً من الألف —
 * فإشعارٌ لثلاثمئة وليّ أمر يحجز عمليةً ثلاثين ثانية، وعددُ العمليات
 * المتزامنة أندرُ ما تملكه الاستضافة المشتركة.
 */
class NotificationQueueTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role = UserRole::Guardian): User
    {
        return User::factory()->role($role)->create([
            'school_id' => School::factory()->create()->id,
        ]);
    }

    public function test_delivery_is_queued_not_run_inside_the_request(): void
    {
        Queue::fake();

        $user = $this->user();

        NotificationGate::notify(
            user: $user,
            key: 'message_received',
            title: 'عنوان',
            body: 'نصّ',
            refId: 1,
            app: NotificationApp::Guardian,
        );

        // لو بقي التسليم داخل الطلب لما وُجدت مهمّة في الطابور أصلاً.
        Queue::assertPushed(DeliverNotification::class);
    }

    public function test_the_notification_row_is_written_before_the_job_runs(): void
    {
        Queue::fake();

        $user = $this->user();

        $notification = NotificationGate::notify(
            user: $user,
            key: 'message_received',
            title: 'عنوان',
            body: 'نصّ',
        );

        // سجلّ الإشعار هو مصدر الحقيقة: شاشة الإشعارات تقرؤه فوراً، ولا تنتظر
        // خروج الدفعة إلى الهاتف.
        $this->assertNotNull($notification);
        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'user_id' => $user->id,
            'type' => 'message_received',
        ]);
    }

    public function test_a_muted_key_queues_nothing_at_all(): void
    {
        Queue::fake();

        $user = $this->user();
        $user->notificationSettings()->create([
            'key' => 'message_received',
            'is_enabled' => false,
        ]);

        $result = NotificationGate::notify(
            user: $user,
            key: 'message_received',
            title: 'عنوان',
        );

        $this->assertNull($result);
        Queue::assertNothingPushed();
    }

    public function test_a_broadcast_queues_one_job_per_recipient(): void
    {
        Queue::fake();

        $school = School::factory()->create();
        $recipients = User::factory()->count(5)->role(UserRole::Guardian)->create([
            'school_id' => $school->id,
        ]);

        foreach ($recipients as $recipient) {
            NotificationGate::notify(
                user: $recipient,
                key: 'post_published',
                title: 'إعلان',
                app: NotificationApp::Guardian,
            );
        }

        // خمس مهامّ تخرج معاً، فيتولّاها العامل بدل أن تتتابع داخل طلبٍ واحد.
        Queue::assertPushed(DeliverNotification::class, 5);
    }
}
