<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\School;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * سعر المادة ليس خطةَ صفّ، وإن كانا يسكنان الجدول نفسه.
 *
 * الخلط ظهر عند المستخدم: شاشة «اختر الخطة الكاملة» عرضت ستّاً وثلاثين
 * مادةً مع أربع خطط، فصار اختيار «رياضيات — تاسع» ممكناً حيث يُنتظَر
 * «خطة كاملة — تاسع» — أي تسجيل طالبٍ سنةً كاملة بسعر مادةٍ واحدة.
 */
class FeeTypeScopeTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Grade $grade;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $year = AcademicYear::factory()->current()->create(['school_id' => $this->school->id]);
        $term = Term::factory()->create(['academic_year_id' => $year->id]);
        $this->grade = Grade::factory()->create(['school_id' => $this->school->id, 'name' => 'تاسع']);

        $subject = Subject::factory()->create([
            'grade_id' => $this->grade->id,
            'term_id' => $term->id,
            'name' => 'رياضيات',
        ]);

        FeeType::factory()->create([
            'school_id' => $this->school->id,
            'grade_id' => $this->grade->id,
            'name' => 'خطة كاملة — تاسع',
            'total_minor' => 6_000_000,
        ]);

        FeeType::factory()->create([
            'school_id' => $this->school->id,
            'grade_id' => $this->grade->id,
            'subject_id' => $subject->id,
            'name' => 'رياضيات — تاسع',
            'total_minor' => 1_200_000,
        ]);

        Sanctum::actingAs(
            User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]),
        );
    }

    /** @return array<int, string> */
    private function names(string $query = ''): array
    {
        return array_column(
            $this->getJson("/api/fee-types{$query}")->assertOk()->json('data'),
            'name',
        );
    }

    public function test_plans_only_hides_subject_prices(): void
    {
        $this->assertSame(['خطة كاملة — تاسع'], $this->names('?plans_only=1'));
    }

    public function test_subjects_only_hides_plans(): void
    {
        $this->assertSame(['رياضيات — تاسع'], $this->names('?subjects_only=1'));
    }

    public function test_the_management_screen_still_sees_both(): void
    {
        // شاشة إدارة الأنواع تحرّر الاثنين، فلا تُحجَب عنها إحداهما.
        $this->assertCount(2, $this->names());
    }

    public function test_the_grade_filter_still_applies(): void
    {
        $other = Grade::factory()->create(['school_id' => $this->school->id, 'name' => 'أدبي']);
        FeeType::factory()->create([
            'school_id' => $this->school->id,
            'grade_id' => $other->id,
            'name' => 'خطة كاملة — أدبي',
            'total_minor' => 6_500_000,
        ]);

        $this->assertSame(
            ['خطة كاملة — تاسع'],
            $this->names("?plans_only=1&grade_id={$this->grade->id}"),
        );
    }
}
