<?php

namespace Tests\Feature;

use App\Models\FeePlan;
use App\Models\FeePlanInstallment;
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

    public function test_an_installment_reminder_points_at_the_plans_student_not_at_a_plan(): void
    {
        $plan = FeePlan::factory()->create();
        $installment = FeePlanInstallment::factory()->create(['fee_plan_id' => $plan->id]);

        $this->assertSame($plan->student_id, $this->target('fee_due', $installment->id));
        $this->assertSame($plan->student_id, $this->target('fee_payment_recorded', $plan->id));
    }

    public function test_unrelated_types_point_nowhere(): void
    {
        $this->assertNull($this->target('post_published', 1));
    }
}
