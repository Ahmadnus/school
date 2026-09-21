<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Services\StudentSubjects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الطالب المسجَّل بمواد مختارة لا يدرس مواد صفّه كلّها.
 *
 * الاختيار كان يُحفظ ولا يُقرأ: مَن سجّل الفرنسيّة وحدها يظهر بتسع مواد،
 * فتُفتح له صفوف علامات وغياب في مواد لم يسجّلها.
 */
class ChosenSubjectsTest extends TestCase
{
    use RefreshDatabase;

    private Student $student;

    private StudentEnrollment $enrollment;

    /** @var array<string, Subject> */
    private array $subjects = [];

    protected function setUp(): void
    {
        parent::setUp();

        $school = School::factory()->create();
        $year = AcademicYear::factory()->current()->create(['school_id' => $school->id]);
        $term = Term::factory()->create(['academic_year_id' => $year->id]);
        $grade = Grade::factory()->create(['school_id' => $school->id]);
        $section = Section::factory()->create([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
        ]);

        foreach (['لغة فرنسية', 'رياضيات', 'تاريخ'] as $name) {
            $this->subjects[$name] = Subject::factory()->create([
                'grade_id' => $grade->id,
                'term_id' => $term->id,
                'name' => $name,
            ]);
        }

        $this->student = Student::factory()->create(['school_id' => $school->id]);
        $this->enrollment = StudentEnrollment::factory()->create([
            'student_id' => $this->student->id,
            'section_id' => $section->id,
            'academic_year_id' => $year->id,
        ]);

        User::factory()->role(UserRole::Admin)->create(['school_id' => $school->id]);
    }

    /** @return array<int, string> */
    private function listed(): array
    {
        $result = StudentSubjects::for($this->student);

        return array_map(
            fn (array $row) => $row['name'],
            $result['subjects'],
        );
    }

    public function test_a_student_with_chosen_subjects_sees_only_those(): void
    {
        $this->enrollment->subjects()->sync([$this->subjects['لغة فرنسية']->id]);

        $this->assertSame(['لغة فرنسية'], $this->listed());
    }

    public function test_choosing_two_subjects_lists_exactly_two(): void
    {
        $this->enrollment->subjects()->sync([
            $this->subjects['رياضيات']->id,
            $this->subjects['تاريخ']->id,
        ]);

        $listed = $this->listed();
        sort($listed);

        $this->assertSame(['تاريخ', 'رياضيات'], $listed);
    }

    public function test_no_choice_means_the_full_year_plan(): void
    {
        // تسجيلٌ بلا اختيار هو الخطة الكاملة — لا يجوز أن يفرغ الجدول.
        $this->assertCount(3, $this->listed());
    }
}
