<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\SendWhatsAppNotice;
use App\Models\AbsenceExcuse;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\Guardian;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentGuardian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * الغياب يصل الأهل على واتساب إلى جانب التطبيق — الغياب وحده، مرّةً في
 * اليوم، وفي وضع التجربة إلى الأرقام المسموحة وحدها.
 */
class AbsenceWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private Section $section;

    private Student $present;

    private Student $absent;

    private Guardian $absentGuardian;

    private Guardian $presentGuardian;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.whatsapp.url', 'http://waha.test');
        config()->set('services.whatsapp.key', 'secret');
        config()->set('services.whatsapp.absence', true);
        config()->set('services.whatsapp.only_to', []);
        Queue::fake([SendWhatsAppNotice::class]);

        $school = School::factory()->create(['phone_country_code' => '963']);
        $year = AcademicYear::factory()->current()->create(['school_id' => $school->id]);
        $grade = Grade::factory()->create(['school_id' => $school->id]);
        $this->section = Section::factory()->create(['grade_id' => $grade->id, 'academic_year_id' => $year->id]);

        foreach (['present', 'absent'] as $role) {
            $student = Student::factory()->create(['school_id' => $school->id]);
            StudentEnrollment::factory()->create([
                'student_id' => $student->id,
                'section_id' => $this->section->id,
                'academic_year_id' => $year->id,
            ]);
            // وليّ أمرٍ بلا حساب في التطبيق — واتساب يصله مع ذلك.
            $guardian = Guardian::factory()->create(['school_id' => $school->id, 'user_id' => null]);
            StudentGuardian::factory()->create(['student_id' => $student->id, 'guardian_id' => $guardian->id]);
            $this->{$role} = $student;
            $this->{$role.'Guardian'} = $guardian;
        }

        Sanctum::actingAs(User::factory()->role(UserRole::Admin)->create(['school_id' => $school->id]));
    }

    private function submit(string $date = '2026-10-05'): void
    {
        $this->postJson("/api/sections/{$this->section->id}/attendance", [
            'date' => $date,
            'records' => [
                ['student_id' => $this->present->id, 'status' => 'present'],
                ['student_id' => $this->absent->id, 'status' => 'absent'],
            ],
            'submit' => true,
        ])->assertSuccessful();
    }

    public function test_only_the_absent_students_family_gets_a_whatsapp(): void
    {
        $this->submit();

        Queue::assertPushed(SendWhatsAppNotice::class, 1);
        Queue::assertPushed(
            SendWhatsAppNotice::class,
            fn (SendWhatsAppNotice $job) => $job->phone === $this->absentGuardian->phone
                && str_contains($job->text, $this->absent->full_name),
        );
    }

    public function test_resubmitting_the_roll_call_does_not_repeat_the_message(): void
    {
        $this->submit();
        $this->submit();

        Queue::assertPushed(SendWhatsAppNotice::class, 1);
    }

    public function test_an_accepted_excuse_means_no_message(): void
    {
        AbsenceExcuse::factory()->accepted()->create([
            'student_id' => $this->absent->id,
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-05',
        ]);

        $this->submit();

        Queue::assertNotPushed(SendWhatsAppNotice::class);
    }

    public function test_trial_mode_reaches_only_the_listed_numbers(): void
    {
        // رقم الأهل المسجَّل غير مدرجٍ في قائمة التجربة: لا رسالة.
        config()->set('services.whatsapp.only_to', ['0964626178']);
        $this->submit();
        Queue::assertNotPushed(SendWhatsAppNotice::class);

        // والرقم المدرَج يصله بأيّ صيغةٍ كُتب.
        $this->absentGuardian->update(['phone' => '0964626178']);
        config()->set('services.whatsapp.only_to', ['+963964626178']);
        $this->submit('2026-10-06');
        Queue::assertPushed(SendWhatsAppNotice::class, 1);
    }

    public function test_messages_leave_one_by_one_spaced_apart(): void
    {
        config()->set('services.whatsapp.spacing', 5);
        $this->travelTo('2026-10-05 08:00:00');

        // صفٌّ فيه ثلاثة غائبين: ثلاث رسائل، كل واحدة بعد سابقتها بخمس ثوانٍ.
        $absent = [$this->absent];
        foreach (range(1, 2) as $_) {
            $student = Student::factory()->create(['school_id' => $this->absent->school_id]);
            StudentEnrollment::factory()->create([
                'student_id' => $student->id,
                'section_id' => $this->section->id,
                'academic_year_id' => $this->section->academic_year_id,
            ]);
            $guardian = Guardian::factory()->create(['school_id' => $this->absent->school_id]);
            StudentGuardian::factory()->create(['student_id' => $student->id, 'guardian_id' => $guardian->id]);
            $absent[] = $student;
        }

        $this->postJson("/api/sections/{$this->section->id}/attendance", [
            'date' => '2026-10-05',
            'records' => array_map(fn (Student $s) => ['student_id' => $s->id, 'status' => 'absent'], $absent),
            'submit' => true,
        ])->assertSuccessful();

        $delays = [];
        Queue::assertPushed(SendWhatsAppNotice::class, function (SendWhatsAppNotice $job) use (&$delays) {
            $delays[] = $job->delay->getTimestamp() - now()->getTimestamp();

            return true;
        });
        sort($delays);

        $this->assertSame([0, 5, 10], $delays);
    }

    public function test_nothing_leaves_when_the_gateway_is_not_configured(): void
    {
        config()->set('services.whatsapp.url', null);

        $this->submit();

        Queue::assertNotPushed(SendWhatsAppNotice::class);
    }
}
