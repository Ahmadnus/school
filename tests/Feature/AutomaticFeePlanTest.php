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
use App\Models\User;
use App\Services\EnrollmentFeePlanner;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * القسط يتبع الإسناد إلى صف — من أي مسار جاء التسجيل، وبلا مضاعفة.
 */
class AutomaticFeePlanTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Section $section;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->year = AcademicYear::factory()->current()->create(['school_id' => $this->school->id]);
        $grade = Grade::factory()->create([
            'school_id' => $this->school->id,
            'name' => 'بكالوريا علمي',
        ]);
        $this->section = Section::factory()->create([
            'grade_id' => $grade->id,
            'academic_year_id' => $this->year->id,
        ]);

        FeeType::factory()->create([
            'school_id' => $this->school->id,
            'grade_id' => $grade->id,
            'is_default' => true,
            'total_minor' => '2000000',
        ]);

        Sanctum::actingAs(
            User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]),
        );
    }

    private function student(): Student
    {
        return Student::factory()->create(['school_id' => $this->school->id]);
    }

    public function test_enrolling_from_the_student_profile_creates_the_plan(): void
    {
        $student = $this->student();

        $this->postJson("/api/students/{$student->id}/enrollments", [
            'section_id' => $this->section->id,
            'academic_year_id' => $this->year->id,
            'scope' => 'full_year',
            'enrolled_at' => '2026-09-01',
        ])->assertCreated();

        $plan = FeePlan::query()->where('student_id', $student->id)->firstOrFail();

        $this->assertTrue($plan->total_minor->equals(Money::fromDecimal('2000000')));
    }

    public function test_calling_the_planner_twice_does_not_double_the_fees(): void
    {
        $student = $this->student();

        $enrollment = $student->enrollments()->create([
            'section_id' => $this->section->id,
            'academic_year_id' => $this->year->id,
            'scope' => 'full_year',
            'enrolled_at' => '2026-09-01',
        ]);

        EnrollmentFeePlanner::ensureFor($enrollment);
        EnrollmentFeePlanner::ensureFor($enrollment);
        EnrollmentFeePlanner::ensureFor($enrollment);

        // الترفيع الجماعي والاستيراد قد يمرّان على الطالب نفسه أكثر من مرّة،
        // ومضاعفة القسط خطأ مالي لا يكتشفه إلا وليّ الأمر.
        $this->assertSame(1, FeePlan::query()->where('student_id', $student->id)->count());
    }

    public function test_no_plan_is_invented_when_the_grade_has_no_default_type(): void
    {
        FeeType::query()->update(['is_default' => false]);

        $student = $this->student();
        $enrollment = $student->enrollments()->create([
            'section_id' => $this->section->id,
            'academic_year_id' => $this->year->id,
            'scope' => 'full_year',
            'enrolled_at' => '2026-09-01',
        ]);

        $this->assertNull(EnrollmentFeePlanner::ensureFor($enrollment));
        $this->assertSame(0, FeePlan::count());
    }

    public function test_a_zero_priced_default_creates_nothing(): void
    {
        FeeType::query()->update(['total_minor' => 0]);

        $student = $this->student();
        $enrollment = $student->enrollments()->create([
            'section_id' => $this->section->id,
            'academic_year_id' => $this->year->id,
            'scope' => 'full_year',
            'enrolled_at' => '2026-09-01',
        ]);

        // خطة بصفر ليست خطة؛ رقم كاذب في التقارير أسوأ من غيابه.
        $this->assertNull(EnrollmentFeePlanner::ensureFor($enrollment));
        $this->assertSame(0, FeePlan::count());
    }

    public function test_every_student_of_a_bulk_enrolment_gets_the_plan(): void
    {
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->student()->id;
        }

        $this->postJson('/api/enrollments/bulk', [
            'student_ids' => $ids,
            'section_id' => $this->section->id,
            'scope' => 'full_year',
            'enrolled_at' => '2026-09-01',
        ])->assertSuccessful();

        // ثلاثة طلاب، ثلاث خطط — بلا فتح شاشة تعيين الرسوم مرّة واحدة.
        $this->assertSame(3, FeePlan::count());
    }
}
