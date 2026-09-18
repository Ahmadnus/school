<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\Guardian;
use App\Models\ReportCard;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ما لا يجوز أن يصل هاتف وليّ الأمر: عائلات غيره، وعلامات أبناء غيره.
 *
 * القاعدة الموروثة في `ManagesSchoolResource` هي «كل من في المدرسة يقرأ»،
 * وهي تصلح للصفوف والمواد ولا تصلح للبيانات الشخصية — وهذه الاختبارات
 * تحرس الفرق.
 */
class GuardianPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $guardianUser;

    private Student $ownChild;

    private Student $otherChild;

    private Term $term;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $year = AcademicYear::factory()->current()->create(['school_id' => $this->school->id]);
        $grade = Grade::factory()->create(['school_id' => $this->school->id]);
        Section::factory()->create([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
        ]);
        $this->term = Term::factory()->create(['academic_year_id' => $year->id]);

        $this->ownChild = Student::factory()->create(['school_id' => $this->school->id]);
        $this->otherChild = Student::factory()->create(['school_id' => $this->school->id]);

        $this->guardianUser = User::factory()->role(UserRole::Guardian)
            ->create(['school_id' => $this->school->id]);
        $guardian = Guardian::factory()->create([
            'school_id' => $this->school->id,
            'user_id' => $this->guardianUser->id,
        ]);
        StudentGuardian::factory()->create([
            'student_id' => $this->ownChild->id,
            'guardian_id' => $guardian->id,
        ]);

        // عائلة أخرى في المدرسة نفسها.
        $otherGuardian = Guardian::factory()->create(['school_id' => $this->school->id]);
        StudentGuardian::factory()->create([
            'student_id' => $this->otherChild->id,
            'guardian_id' => $otherGuardian->id,
        ]);
    }

    public function test_a_guardian_cannot_read_the_school_guardian_directory(): void
    {
        Sanctum::actingAs($this->guardianUser);

        // القائمة تحمل أسماء العائلات وأرقام هواتفها.
        $this->getJson('/api/guardians')->assertForbidden();
    }

    public function test_staff_still_read_the_guardian_directory(): void
    {
        Sanctum::actingAs(
            User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]),
        );

        $this->getJson('/api/guardians')->assertOk();
    }

    public function test_a_guardian_sees_only_their_own_children_report_cards(): void
    {
        ReportCard::factory()->create([
            'student_id' => $this->ownChild->id,
            'term_id' => $this->term->id,
        ]);
        ReportCard::factory()->create([
            'student_id' => $this->otherChild->id,
            'term_id' => $this->term->id,
        ]);

        Sanctum::actingAs($this->guardianUser);

        $ids = collect($this->getJson('/api/report-cards')->assertOk()->json('data'))
            ->pluck('student_id');

        $this->assertSame([$this->ownChild->id], $ids->all());
    }

    public function test_a_guardian_cannot_reach_another_child_report_card_by_filter(): void
    {
        ReportCard::factory()->create([
            'student_id' => $this->otherChild->id,
            'term_id' => $this->term->id,
        ]);

        Sanctum::actingAs($this->guardianUser);

        // تمرير معرّف ابن غيره صراحةً لا يتجاوز الحصر.
        $data = $this->getJson('/api/report-cards?student_id='.$this->otherChild->id)
            ->assertOk()
            ->json('data');

        $this->assertSame([], $data);
    }

    public function test_staff_see_every_report_card(): void
    {
        ReportCard::factory()->create([
            'student_id' => $this->ownChild->id,
            'term_id' => $this->term->id,
        ]);
        ReportCard::factory()->create([
            'student_id' => $this->otherChild->id,
            'term_id' => $this->term->id,
        ]);

        Sanctum::actingAs(
            User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]),
        );

        $this->assertCount(2, $this->getJson('/api/report-cards')->assertOk()->json('data'));
    }
}
