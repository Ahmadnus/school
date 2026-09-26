<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\FeePayment;
use App\Models\FeePlan;
use App\Models\Guardian;
use App\Models\Notification;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * تذكير المتأخّرين: من يدخل القائمة، ومن لا يدخلها، ومن لا يصله شيء.
 *
 * الخطر هنا ليس أن يفوت تذكير، بل أن يصل تذكيرٌ إلى من سدّد: رسالةٌ واحدة
 * خاطئة تُفقد المدرسة مصداقيّتها وتُدرّب الأهالي على تجاهل رسائلها.
 */
class FeeReminderTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->year = AcademicYear::factory()->current()->create(['school_id' => $this->school->id]);

        Sanctum::actingAs(
            User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]),
        );
    }

    private function student(string $name, bool $withGuardian = true): Student
    {
        $student = Student::factory()->create([
            'school_id' => $this->school->id,
            'first_name' => $name,
            'last_name' => 'تجريبي',
        ]);

        if ($withGuardian) {
            $user = User::factory()->role(UserRole::Guardian)->create(['school_id' => $this->school->id]);
            $guardian = Guardian::factory()->create([
                'school_id' => $this->school->id,
                'user_id' => $user->id,
            ]);
            StudentGuardian::factory()->create([
                'student_id' => $student->id,
                'guardian_id' => $guardian->id,
            ]);
        }

        return $student;
    }

    private function plan(Student $student, int $total, ?int $paid = null, ?string $paidOn = null): FeePlan
    {
        $plan = FeePlan::factory()->create([
            'student_id' => $student->id,
            'academic_year_id' => $this->year->id,
            'total_minor' => $total,
        ]);

        // الخطة القديمة: من فُتحت خطّته اليوم لا يُطالَب غداً.
        $plan->forceFill(['created_at' => Carbon::today()->subDays(90)])->save();

        if ($paid !== null) {
            FeePayment::factory()->create([
                'school_id' => $this->school->id,
                'fee_plan_id' => $plan->id,
                'amount_minor' => $paid,
                'paid_on' => $paidOn ?? Carbon::today()->toDateString(),
            ]);
        }

        return $plan;
    }

    /** @return array<int, string> */
    private function candidates(int $days = 30): array
    {
        return array_column(
            $this->getJson("/api/fees/reminders?days={$days}")->assertOk()->json('data'),
            'student_name',
        );
    }

    public function test_someone_who_never_paid_is_listed(): void
    {
        $this->plan($this->student('غسان'), 1_000_000);

        $this->assertSame(['غسان تجريبي'], $this->candidates());
    }

    public function test_someone_who_paid_recently_is_not_listed(): void
    {
        // سدّد جزءاً أمس: عليه متبقٍّ لكنه ليس متأخّراً.
        $this->plan($this->student('سامر'), 1_000_000, 200_000, Carbon::yesterday()->toDateString());

        $this->assertSame([], $this->candidates());
    }

    public function test_an_old_partial_payment_is_listed_again(): void
    {
        $this->plan(
            $this->student('رامي'),
            1_000_000,
            200_000,
            Carbon::today()->subDays(45)->toDateString(),
        );

        $this->assertSame(['رامي تجريبي'], $this->candidates());
    }

    public function test_someone_fully_paid_is_never_listed(): void
    {
        $this->plan(
            $this->student('وفاء'),
            1_000_000,
            1_000_000,
            Carbon::today()->subDays(200)->toDateString(),
        );

        // سدّد كاملاً قبل مئتَي يوم: قِدَم الدفعة لا يجعله مديناً.
        $this->assertSame([], $this->candidates());
    }

    public function test_sending_notifies_only_the_chosen(): void
    {
        $owing = $this->student('غسان');
        $other = $this->student('نادر');
        $this->plan($owing, 1_000_000);
        $this->plan($other, 1_000_000);

        $this->postJson('/api/fees/reminders', [
            'student_ids' => [$owing->id],
        ])->assertOk()->assertJsonPath('data.sent', 1);

        $this->assertSame(1, Notification::where('type', 'fee_due')->count());
        $this->assertStringContainsString(
            'غسان',
            Notification::where('type', 'fee_due')->value('title'),
        );
    }

    public function test_a_student_who_paid_in_between_is_skipped_at_send_time(): void
    {
        $student = $this->student('سدَّد');
        $plan = $this->plan($student, 1_000_000);

        // القائمة فُتحت وهو مدين، ثم سدّد قبل أن يُضغط «إرسال».
        FeePayment::factory()->create([
            'school_id' => $this->school->id,
            'fee_plan_id' => $plan->id,
            'amount_minor' => 1_000_000,
            'paid_on' => Carbon::today()->toDateString(),
        ]);

        $this->postJson('/api/fees/reminders', [
            'student_ids' => [$student->id],
        ])->assertOk()->assertJsonPath('data.sent', 0);

        $this->assertSame(0, Notification::where('type', 'fee_due')->count());
    }

    public function test_a_student_without_a_guardian_is_reported_not_counted(): void
    {
        $student = $this->student('يتيم الحساب', withGuardian: false);
        $this->plan($student, 1_000_000);

        $response = $this->postJson('/api/fees/reminders', [
            'student_ids' => [$student->id],
        ])->assertOk();

        // إخفاء هؤلاء يجعل المدرسة تظنّ أنها طالبت وهي لم تفعل.
        $this->assertSame(0, $response->json('data.sent'));
        $this->assertSame(['يتيم الحساب تجريبي'], $response->json('data.without_guardian'));
    }
}
