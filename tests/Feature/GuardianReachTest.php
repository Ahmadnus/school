<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Events\NotificationCreated;
use App\Jobs\DeliverNotification;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\Guardian;
use App\Models\Notification;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentCard;
use App\Models\StudentEnrollment;
use App\Models\StudentGuardian;
use App\Models\User;
use App\Services\NotificationGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ما يجري للطالب يصل أهله فوراً — البثّ لا ينتظر الطابور، ووصول البوّابة يُبلَّغ.
 */
class GuardianReachTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_realtime_broadcast_leaves_at_once_not_through_the_queue(): void
    {
        Queue::fake();
        Event::fake([NotificationCreated::class]);
        $user = User::factory()->role(UserRole::Teacher)->create([
            'school_id' => School::factory()->create()->id,
        ]);

        NotificationGate::notify($user, 'message_received', 'title');

        Event::assertDispatched(NotificationCreated::class);
        Queue::assertPushed(DeliverNotification::class);
    }

    public function test_the_first_gate_scan_of_the_day_tells_the_family(): void
    {
        Queue::fake();
        $school = School::factory()->create();
        $year = AcademicYear::factory()->current()->create(['school_id' => $school->id]);
        $grade = Grade::factory()->create(['school_id' => $school->id]);
        $section = Section::factory()->create(['grade_id' => $grade->id, 'academic_year_id' => $year->id]);

        $student = Student::factory()->create(['school_id' => $school->id]);
        StudentEnrollment::factory()->create([
            'student_id' => $student->id,
            'section_id' => $section->id,
            'academic_year_id' => $year->id,
        ]);

        $user = User::factory()->role(UserRole::Guardian)->create(['school_id' => $school->id]);
        $guardian = Guardian::factory()->create(['school_id' => $school->id, 'user_id' => $user->id]);
        StudentGuardian::factory()->create(['student_id' => $student->id, 'guardian_id' => $guardian->id]);

        $card = StudentCard::factory()->create(['student_id' => $student->id]);
        Sanctum::actingAs(User::factory()->role(UserRole::Admin)->create(['school_id' => $school->id]));

        $this->postJson('/api/gate/scans', ['nfc_uid' => $card->nfc_uid])->assertCreated();
        $this->postJson('/api/gate/scans', ['nfc_uid' => $card->nfc_uid])->assertOk();

        $this->assertSame(1, Notification::query()
            ->where('user_id', $user->id)
            ->where('type', 'gate_arrival')
            ->count());
    }
}
