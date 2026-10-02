<?php

namespace Tests\Feature;

use App\Models\FeePlan;
use App\Models\FeePlanInstallment;
use App\Models\Guardian;
use App\Services\GuardianAccount;
use App\Models\Notification;
use App\Models\Student;
use App\Services\NotificationTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** النقر على إشعارٍ يخصّ طالباً يفتح ذلك الطالب — أيّاً كان ما يحمله `ref_id`. */
class NotificationTargetTest extends TestCase
{
    use RefreshDatabase;

    private function target(string $type, int $refId): ?int
    {
        return NotificationTarget::studentIdFor(
            Notification::factory()->create(['type' => $type, 'ref_id' => $refId]),
        );
    }

    public function test_types_that_carry_the_student_point_at_it(): void
    {
        $student = Student::factory()->create();

        foreach (['attendance_absence', 'attendance_late', 'gate_arrival', 'tasmi_recorded', 'grade_published'] as $type) {
            $this->assertSame($student->id, $this->target($type, $student->id), $type);
        }
    }

    /** الأب صاحب الإشعار وابنه — فـ`fee_due` لا يُحلّ إلّا إلى ابنه هو. */
    private function feeDueFor(Student $child, int $refId): ?int
    {
        $guardian = Guardian::factory()->create(['school_id' => $child->school_id]);
        $child->guardians()->attach($guardian->id, ['relation' => 'father', 'is_primary' => true]);
        $user = GuardianAccount::for($guardian);

        return NotificationTarget::studentIdFor(Notification::factory()->create([
            'user_id' => $user->id, 'type' => 'fee_due', 'ref_id' => $refId,
        ]));
    }

    public function test_an_installment_reminder_points_at_the_plans_student_not_at_a_plan(): void
    {
        $plan = FeePlan::factory()->create();
        $installment = FeePlanInstallment::factory()->create(['fee_plan_id' => $plan->id]);

        $this->assertSame($plan->student_id, $this->feeDueFor($plan->student, $installment->id));
        $this->assertSame($plan->student_id, $this->target('fee_payment_recorded', $plan->id));
    }

    public function test_the_offices_manual_reminder_carries_the_student_and_still_opens_it(): void
    {
        $child = Student::factory()->create();

        $this->assertSame($child->id, $this->feeDueFor($child, $child->id));
    }

    public function test_a_reminder_never_opens_someone_elses_child(): void
    {
        $mine = Student::factory()->create();
        $stranger = Student::factory()->create();

        $this->assertNull($this->feeDueFor($mine, $stranger->id));
    }

    public function test_unrelated_types_point_nowhere(): void
    {
        $this->assertNull($this->target('post_published', 1));
    }
}
