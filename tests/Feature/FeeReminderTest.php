<?php

namespace Tests\Feature;

use App\Enums\FeePlanStatus;
use App\Enums\NotificationApp;
use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\FeePayment;
use App\Models\FeePlan;
use App\Models\Guardian;
use App\Models\Notification;
use App\Models\School;
use App\Models\SchoolNotificationSetting;
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

    /** الرقم فريد داخل المدرسة، فيُزاد مع كل وليّ أمر. */
    private int $phones = 6000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->year = AcademicYear::factory()->current()->create(['school_id' => $this->school->id]);

        Sanctum::actingAs(
            User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]),
        );
    }

    private function student(string $name, ?bool $withGuardian = true): Student
    {
        $student = Student::factory()->create([
            'school_id' => $this->school->id,
            'first_name' => $name,
            'last_name' => 'تجريبي',
        ]);

        if ($withGuardian !== null) {
            // حساب `users` يُنشأ عند أوّل تسجيل دخول بالـ OTP، فوليّ أمر
            // مُدخَل ولم يفتح التطبيق بعد يبقى بلا user_id.
            $user = $withGuardian
                ? User::factory()->role(UserRole::Guardian)->create(['school_id' => $this->school->id])
                : null;
            $guardian = Guardian::factory()->create([
                'school_id' => $this->school->id,
                'user_id' => $user?->id,
                'phone' => '+96395555'.str_pad((string) ++$this->phones, 4, '0', STR_PAD_LEFT),
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

    public function test_a_guardian_who_never_signed_in_is_reported_separately(): void
    {
        // أُدخل اسمه ورقمه ولم يفتح تطبيق الأهالي بعد: حالتُه تُتابَع
        // بمكالمة، لا بإدخال وليّ أمرٍ جديد. خلطها بالحالة الأخرى يُخفي
        // الفرق عن المدرسة.
        $student = $this->student('ابن غير المسجِّل', withGuardian: false);
        $this->plan($student, 1_000_000);

        $response = $this->postJson('/api/fees/reminders', [
            'student_ids' => [$student->id],
        ])->assertOk();

        $this->assertSame(0, $response->json('data.sent'));
        $this->assertSame(['ابن غير المسجِّل تجريبي'], $response->json('data.not_signed_in'));
        $this->assertSame([], $response->json('data.without_guardian'));
    }

    public function test_the_pending_contact_phone_is_listed_for_follow_up(): void
    {
        $student = $this->student('ابن غير المسجِّل', withGuardian: false);
        $this->plan($student, 1_000_000);

        // الرقم يُعرَض حتى يُتّصل به، لا ليُقال إنّ هناك مشكلة فقط.
        $contacts = $this->getJson('/api/fees/reminders?days=30')
            ->assertOk()
            ->json('data.0.pending_contacts');

        $this->assertCount(1, $contacts);
        $this->assertStringContainsString('+96395555', $contacts[0]);
    }

    public function test_a_student_without_a_guardian_is_reported_not_counted(): void
    {
        $student = $this->student('يتيم الحساب', withGuardian: null);
        $this->plan($student, 1_000_000);

        $response = $this->postJson('/api/fees/reminders', [
            'student_ids' => [$student->id],
        ])->assertOk();

        // إخفاء هؤلاء يجعل المدرسة تظنّ أنها طالبت وهي لم تفعل.
        $this->assertSame(0, $response->json('data.sent'));
        $this->assertSame(['يتيم الحساب تجريبي'], $response->json('data.without_guardian'));
        $this->assertSame([], $response->json('data.not_signed_in'));
    }

    // ------------------------------------------ تقرير التسليم: خمس حالات مفصولة

    /**
     * من سدّد بين العرض والإرسال يُعلَن «لم يبقَ عليه شيء»، لا يُتخطّى صامتاً.
     *
     * كان يُسقَط بلا ذكر، فيقرأ المدير «أُرسل ٣» ويظنّ أنّ الرابع وصله. وسببُ
     * تخطّيه خبرٌ سارّ — سدّد — يستحقّ أن يُقال لا أن يُخفى.
     */
    public function test_a_student_who_paid_before_sending_is_reported_as_no_longer_due(): void
    {
        $paid = $this->student('وفيق');
        $plan = $this->plan($paid, 100_000, 10_000, Carbon::today()->subDays(60)->toDateString());

        $owing = $this->student('ناجي');
        $this->plan($owing, 100_000);

        $this->assertEqualsCanonicalizing(
            ['وفيق تجريبي', 'ناجي تجريبي'],
            $this->candidates(),
        );

        // سدّد الباقي بعد أن عُرضت القائمة.
        FeePayment::factory()->create([
            'school_id' => $this->school->id,
            'fee_plan_id' => $plan->id,
            'amount_minor' => 90_000,
            'paid_on' => Carbon::today()->toDateString(),
        ]);

        $this->postJson('/api/fees/reminders', [
            'student_ids' => [$paid->id, $owing->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.sent', 1)
            ->assertJsonPath('data.no_longer_due', ['وفيق تجريبي'])
            ->assertJsonPath('data.failed', []);

        // ولا إشعار له.
        $this->assertSame(0, Notification::query()->where('ref_id', $paid->id)->count());
    }

    /** ومن أُغلق مفتاحه لا يُحسَب مُرسَلاً: يُعلَن في «فشل». */
    public function test_a_blocked_notification_is_reported_as_failed_not_sent(): void
    {
        $student = $this->student('مروان');
        $this->plan($student, 100_000);

        SchoolNotificationSetting::factory()->create([
            'school_id' => $this->school->id,
            'app' => NotificationApp::Guardian,
            'key' => 'fee_due',
            'group' => 'fees',
            'is_enabled' => false,
        ]);

        $this->postJson('/api/fees/reminders', ['student_ids' => [$student->id]])
            ->assertOk()
            ->assertJsonPath('data.sent', 0)
            ->assertJsonPath('data.failed', ['مروان تجريبي']);

        $this->assertSame(0, Notification::query()->where('type', 'fee_due')->count());
    }

    /** والحالات الخمس كلّها حاضرة في الردّ، ولو كانت فارغة. */
    public function test_the_result_always_reports_all_five_outcomes(): void
    {
        $student = $this->student('سالم');
        $this->plan($student, 100_000);

        $this->postJson('/api/fees/reminders', ['student_ids' => [$student->id]])
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'sent',
                    'not_signed_in',
                    'without_guardian',
                    'no_longer_due',
                    'failed',
                ],
            ]);
    }

    /** واسم وليّ الأمر يُعرَض في الصفّ — المدير يقرأ الاسم لا العدد. */
    public function test_the_candidate_row_carries_the_guardian_name(): void
    {
        $student = $this->student('هاني');
        $this->plan($student, 100_000);

        $row = collect($this->getJson('/api/fees/reminders?days=30')->assertOk()->json('data'))
            ->firstWhere('student_name', 'هاني تجريبي');

        $this->assertNotNull($row);
        $this->assertCount(1, $row['guardian_names']);
        $this->assertNotEmpty($row['guardian_names'][0]);
    }

    /** والصفّ يحمل ما يقرأه المدير ليقرّر: المتبقّي وآخر دفعة ومدّة التأخّر. */
    public function test_the_candidate_row_carries_the_information_needed_to_decide(): void
    {
        $student = $this->student('فادي');
        $this->plan($student, 100_000, 10_000, Carbon::today()->subDays(45)->toDateString());

        $row = collect($this->getJson('/api/fees/reminders?days=30')->assertOk()->json('data'))
            ->firstWhere('student_name', 'فادي تجريبي');

        $this->assertNotNull($row);
        $this->assertSame('90000.00', $row['remaining']);
        $this->assertSame(Carbon::today()->subDays(45)->toDateString(), $row['last_payment_on']);
        $this->assertSame(45, $row['days_since']);
    }

    /** وخيار «شهر» هو الافتراض: ثلاثون يوماً. */
    public function test_one_month_is_the_default_period(): void
    {
        $student = $this->student('زياد');
        $this->plan($student, 100_000, 10_000, Carbon::today()->subDays(40)->toDateString());

        $recent = $this->student('بشار');
        $this->plan($recent, 100_000, 10_000, Carbon::today()->subDays(10)->toDateString());

        // بلا وسيط `days` أصلاً.
        $names = array_column(
            $this->getJson('/api/fees/reminders')->assertOk()->json('data'),
            'student_name',
        );

        $this->assertContains('زياد تجريبي', $names);
        $this->assertNotContains('بشار تجريبي', $names);
        $this->assertSame(30, $this->getJson('/api/fees/reminders')->json('meta.days'));
    }

    /** والمُدَد الثلاث كلّها تعمل: أسبوعان وشهر وشهران. */
    public function test_all_three_periods_filter_correctly(): void
    {
        $twenty = $this->student('عشرون');
        $this->plan($twenty, 100_000, 10_000, Carbon::today()->subDays(20)->toDateString());

        $forty = $this->student('أربعون');
        $this->plan($forty, 100_000, 10_000, Carbon::today()->subDays(40)->toDateString());

        $seventy = $this->student('سبعون');
        $this->plan($seventy, 100_000, 10_000, Carbon::today()->subDays(70)->toDateString());

        // أسبوعان: الثلاثة.
        $this->assertEqualsCanonicalizing(
            ['عشرون تجريبي', 'أربعون تجريبي', 'سبعون تجريبي'],
            $this->candidates(14),
        );

        // شهر: من تجاوز الثلاثين.
        $this->assertEqualsCanonicalizing(
            ['أربعون تجريبي', 'سبعون تجريبي'],
            $this->candidates(30),
        );

        // شهران: الأقدم وحده.
        $this->assertSame(['سبعون تجريبي'], $this->candidates(60));
    }

    /** وخطة ملغاة لا تدخل القائمة ولا يُرسَل لصاحبها. */
    public function test_a_cancelled_plan_is_excluded_from_both_list_and_send(): void
    {
        $student = $this->student('ملغى');
        $plan = $this->plan($student, 100_000);
        $plan->forceFill(['status' => FeePlanStatus::Cancelled])->save();

        $this->assertNotContains('ملغى تجريبي', $this->candidates());

        $this->postJson('/api/fees/reminders', ['student_ids' => [$student->id]])
            ->assertOk()
            ->assertJsonPath('data.sent', 0)
            ->assertJsonPath('data.no_longer_due', ['ملغى تجريبي']);

        $this->assertSame(0, Notification::query()->where('type', 'fee_due')->count());
    }

    /** ومن فُتحت خطّته اليوم لا يُطالَب غداً. */
    public function test_a_brand_new_plan_is_not_overdue_immediately(): void
    {
        $student = $this->student('جديد');

        // خطة اليوم — بلا إزاحة `created_at` إلى الماضي.
        FeePlan::factory()->create([
            'student_id' => $student->id,
            'academic_year_id' => $this->year->id,
            'total_minor' => 100_000,
        ]);

        $this->assertNotContains('جديد تجريبي', $this->candidates(30));
    }
}
