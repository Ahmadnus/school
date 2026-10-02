<?php

namespace Tests\Feature;

use App\Enums\NotificationApp;
use App\Enums\UserRole;
use App\Events\NotificationCreated;
use App\Jobs\DeliverNotification;
use App\Jobs\SendWhatsAppNotice;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\Guardian;
use App\Models\Notification;
use App\Models\School;
use App\Models\SchoolNotificationSetting;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentCard;
use App\Models\StudentEnrollment;
use App\Models\StudentGuardian;
use App\Models\User;
use App\Services\GuardianWhatsApp;
use App\Services\NotificationGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ما يجري للطالب يصل أهله: فوراً على التطبيق، وعلى واتساب لما يستحقّه —
 * حتى لمن لم يفتح التطبيق بعد.
 */
class GuardianReachTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.whatsapp.url', 'http://waha.test');
        config()->set('services.whatsapp.key', 'secret');
        config()->set('services.whatsapp.notify_keys', ['tasmi_recorded', 'behavior_record']);

        Queue::fake();

        $this->school = School::factory()->create(['phone_country_code' => '963']);
        $year = AcademicYear::factory()->current()->create(['school_id' => $this->school->id]);
        $grade = Grade::factory()->create(['school_id' => $this->school->id]);
        $section = Section::factory()->create(['grade_id' => $grade->id, 'academic_year_id' => $year->id]);

        $this->student = Student::factory()->create(['school_id' => $this->school->id]);
        StudentEnrollment::factory()->create([
            'student_id' => $this->student->id,
            'section_id' => $section->id,
            'academic_year_id' => $year->id,
        ]);
    }

    private function guardian(?User $user = null): Guardian
    {
        $guardian = Guardian::factory()->create([
            'school_id' => $this->school->id,
            'user_id' => $user?->id,
            'phone' => '0955556001',
        ]);
        StudentGuardian::factory()->create(['student_id' => $this->student->id, 'guardian_id' => $guardian->id]);

        return $guardian;
    }

    public function test_the_realtime_broadcast_leaves_at_once_not_through_the_queue(): void
    {
        Event::fake([NotificationCreated::class]);
        $user = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);

        NotificationGate::notify($user, 'message_received', 'title');

        Event::assertDispatched(NotificationCreated::class);
        Queue::assertPushed(DeliverNotification::class);
    }

    public function test_a_guardian_without_the_app_still_gets_whatsapp(): void
    {
        GuardianWhatsApp::send($this->guardian(), 'tasmi_recorded', 'تسميع', 'ممتاز');

        Queue::assertPushedOn('whatsapp', SendWhatsAppNotice::class);
    }

    public function test_a_guardian_with_the_app_gets_both(): void
    {
        $user = User::factory()->role(UserRole::Guardian)->create(['school_id' => $this->school->id]);
        $this->guardian($user);

        NotificationGate::notify($user, 'tasmi_recorded', 'تسميع', 'ممتاز', null, NotificationApp::Guardian);

        Queue::assertPushed(DeliverNotification::class);
        Queue::assertPushedOn('whatsapp', SendWhatsAppNotice::class);
    }

    public function test_keys_outside_the_list_stay_off_whatsapp(): void
    {
        GuardianWhatsApp::send($this->guardian(), 'post_published', 'منشور');

        Queue::assertNotPushed(SendWhatsAppNotice::class);
    }

    public function test_a_school_switched_off_key_sends_nothing(): void
    {
        SchoolNotificationSetting::factory()->create([
            'school_id' => $this->school->id,
            'app' => NotificationApp::Guardian,
            'key' => 'tasmi_recorded',
            'is_enabled' => false,
        ]);

        GuardianWhatsApp::send($this->guardian(), 'tasmi_recorded', 'تسميع');

        Queue::assertNotPushed(SendWhatsAppNotice::class);
    }

    public function test_the_first_gate_scan_of_the_day_tells_the_family(): void
    {
        $user = User::factory()->role(UserRole::Guardian)->create(['school_id' => $this->school->id]);
        $this->guardian($user);
        $card = StudentCard::factory()->create(['student_id' => $this->student->id]);
        Sanctum::actingAs(User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]));

        $this->postJson('/api/gate/scans', ['nfc_uid' => $card->nfc_uid])->assertCreated();
        $this->postJson('/api/gate/scans', ['nfc_uid' => $card->nfc_uid])->assertOk();

        $this->assertSame(1, Notification::query()
            ->where('user_id', $user->id)
            ->where('type', 'gate_arrival')
            ->count());
    }
}
