<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\FeePlan;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * تسجيل الطالب وخطته المالية في خطوة واحدة — الخطة الكاملة والمواد المختارة.
 */
class StudentEnrollmentPlanTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Section $section;

    private Grade $grade;

    private FeeType $defaultType;

    /** @var array<int, Subject> */
    private array $subjects = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $year = AcademicYear::factory()->current()->create(['school_id' => $this->school->id]);
        $this->grade = Grade::factory()->create(['school_id' => $this->school->id]);
        $this->section = Section::factory()->create([
            'grade_id' => $this->grade->id,
            'academic_year_id' => $year->id,
        ]);

        // نوع الرسوم الافتراضي للصف: منه تأتي الخطة الكاملة.
        $this->defaultType = FeeType::factory()->create([
            'school_id' => $this->school->id,
            'grade_id' => $this->grade->id,
            'is_default' => true,
            'total_minor' => 1_000_000,
        ]);

        foreach (['رياضيات', 'فيزياء', 'كيمياء'] as $name) {
            $this->subjects[] = Subject::factory()->create([
                'grade_id' => $this->grade->id,
                'name' => $name,
            ]);
        }

        Sanctum::actingAs(
            User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]),
        );
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return [
            'first_name' => 'طالب',
            'last_name' => 'جديد',
            'section_id' => $this->section->id,
            'scope' => 'full_year',
            'enrolled_at' => '2026-09-01',
            ...$overrides,
        ];
    }

    public function test_a_full_plan_is_created_from_the_grade_default_fee_type(): void
    {
        $this->postJson('/api/students', $this->payload([
            'plan_mode' => 'full',
        ]))->assertCreated();

        $plan = FeePlan::query()->firstOrFail();

        // المبلغ جاء من نوع الصف بلا أن يكتبه أحد.
        $this->assertSame($this->defaultType->id, $plan->fee_type_id);
        $this->assertTrue($plan->total_minor->equals($this->defaultType->totalAmount()));
    }

    public function test_an_explicitly_chosen_plan_beats_the_grade_default(): void
    {
        // المستخدم يفكّر بـ«قسط العلمي» لا بـ«النوع الموسوم افتراضيّاً»؛
        // فإن اختار خطة بعينها فهي التي تُطبّق، ولو كان للصف افتراضي غيرها.
        $other = FeeType::factory()->create([
            'school_id' => $this->school->id,
            'grade_id' => $this->grade->id,
            'is_default' => false,
            'total_minor' => '3333333',
        ]);

        $this->postJson('/api/students', $this->payload([
            'plan_mode' => 'full',
            'plan_fee_type_id' => $other->id,
        ]))->assertCreated();

        $plan = FeePlan::query()->firstOrFail();

        $this->assertSame($other->id, $plan->fee_type_id);
        $this->assertTrue($plan->total_minor->equals($other->totalAmount()));
    }

    public function test_selected_subjects_are_saved_with_the_price_typed_by_hand(): void
    {
        $ids = [$this->subjects[0]->id, $this->subjects[1]->id];

        $this->postJson('/api/students', $this->payload([
            'plan_mode' => 'subjects',
            'subject_ids' => $ids,
            'plan_total_amount' => '300.00',
        ]))->assertCreated();

        $student = Student::query()->firstOrFail();
        $enrollment = $student->enrollments()->firstOrFail();

        $this->assertEqualsCanonicalizing(
            $ids,
            $enrollment->subjects()->pluck('subjects.id')->all(),
        );

        $plan = FeePlan::query()->firstOrFail();
        // المبلغ اليدوي يسود، ولا يُنسب إلى نوع رسوم الصف.
        $this->assertTrue($plan->total_minor->equals(Money::fromDecimal('300.00')));
        $this->assertNull($plan->fee_type_id);
    }

    public function test_the_full_plan_records_no_subjects_because_it_is_the_whole_programme(): void
    {
        $this->postJson('/api/students', $this->payload([
            'plan_mode' => 'full',
        ]))->assertCreated();

        $enrollment = Student::query()->firstOrFail()->enrollments()->firstOrFail();

        $this->assertFalse($enrollment->studiesSelectedSubjects());
    }

    public function test_choosing_subjects_without_a_price_is_rejected(): void
    {
        $this->postJson('/api/students', $this->payload([
            'plan_mode' => 'subjects',
            'subject_ids' => [$this->subjects[0]->id],
        ]))->assertStatus(422)->assertJsonValidationErrors('plan_total_amount');

        $this->assertSame(0, Student::count());
    }

    public function test_subjects_mode_without_any_subject_is_rejected(): void
    {
        $this->postJson('/api/students', $this->payload([
            'plan_mode' => 'subjects',
            'plan_total_amount' => '300.00',
        ]))->assertStatus(422)->assertJsonValidationErrors('subject_ids');
    }

    public function test_a_student_can_still_be_created_without_any_plan(): void
    {
        $this->postJson('/api/students', $this->payload([
            'plan_mode' => 'none',
        ]))->assertCreated();

        $this->assertSame(1, Student::count());
        $this->assertSame(0, FeePlan::count());
    }

    public function test_no_plan_is_invented_when_the_grade_has_no_default_type(): void
    {
        $this->defaultType->forceFill(['is_default' => false])->save();

        $this->postJson('/api/students', $this->payload([
            'plan_mode' => 'full',
        ]))->assertCreated();

        // الطالب يُسجَّل، لكن خطة بصفر ليست خطة — ورقم كاذب في التقارير أسوأ من غيابه.
        $this->assertSame(1, Student::count());
        $this->assertSame(0, FeePlan::count());
    }

    public function test_a_discount_at_enrolment_needs_a_reason(): void
    {
        $this->postJson('/api/students', $this->payload([
            'plan_mode' => 'full',
            'plan_discount_amount' => '100.00',
        ]))->assertStatus(422)->assertJsonValidationErrors('plan_discount_reason');
    }
}
