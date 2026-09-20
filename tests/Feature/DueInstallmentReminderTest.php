<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\FeePlan;
use App\Models\FeePlanInstallment;
use App\Models\Guardian;
use App\Models\Notification;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * تذكير القسط المستحقّ — الإشعار الوحيد الذي لا يُطلقه أحد.
 */
class DueInstallmentReminderTest extends TestCase
{
    use RefreshDatabase;

    private Student $child;

    private User $guardianUser;

    private FeePlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $school = School::factory()->create();
        $year = AcademicYear::factory()->current()->create(['school_id' => $school->id]);
        $this->child = Student::factory()->create(['school_id' => $school->id]);

        $this->guardianUser = User::factory()->role(UserRole::Guardian)->create(['school_id' => $school->id]);
        $guardian = Guardian::factory()->create(['school_id' => $school->id, 'user_id' => $this->guardianUser->id]);
        StudentGuardian::factory()->create(['student_id' => $this->child->id, 'guardian_id' => $guardian->id]);

        $this->plan = FeePlan::factory()->create([
            'student_id' => $this->child->id,
            'academic_year_id' => $year->id,
            'total_minor' => 1000,
        ]);
    }

    private function installment(string $dueDate): FeePlanInstallment
    {
        return FeePlanInstallment::factory()->create([
            'fee_plan_id' => $this->plan->id,
            'sort_order' => 1,
            'due_date' => $dueDate,
            'amount_minor' => 1000,
        ]);
    }

    private function reminders(): int
    {
        return Notification::where('user_id', $this->guardianUser->id)
            ->where('type', 'fee_due')
            ->count();
    }

    public function test_an_installment_due_soon_reminds_the_guardian(): void
    {
        $this->installment(Carbon::today()->addDay()->toDateString());

        $this->artisan('fees:notify-due')->assertSuccessful();

        $this->assertSame(1, $this->reminders());
    }

    public function test_running_twice_does_not_nag_twice(): void
    {
        $this->installment(Carbon::today()->addDay()->toDateString());

        $this->artisan('fees:notify-due')->assertSuccessful();
        $this->artisan('fees:notify-due')->assertSuccessful();

        // جدولة يومية تعني تشغيلاً متكرّراً؛ بلا الحارس يصير التذكير مضايقة.
        $this->assertSame(1, $this->reminders());
    }

    public function test_a_distant_installment_is_left_alone(): void
    {
        $this->installment(Carbon::today()->addDays(30)->toDateString());

        $this->artisan('fees:notify-due')->assertSuccessful();

        $this->assertSame(0, $this->reminders());
    }
}
