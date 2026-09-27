<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\Grade;
use App\Models\GradeScore;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * حقل إدخال الدرجة يحمل رقماً، لا كائناً.
 *
 * العطل الذي تحرسه هذه الاختبارات: كشف الدرجات كان يُعيد في المفتاح `score`
 * مورِدَ السجلّ كاملاً (`GradeScoreResource`)، فيقرؤه التطبيق نصّاً ويكتبه في
 * الحقل، فيرى الأستاذ `{id: 41, assessment_id: 3, score: 85.00, …}` مكان «85».
 * فالعقد صار: `score` رقم مجرَّد، و`record` هو السجلّ لمن يحتاجه.
 */
class GradeEntryFieldTest extends TestCase
{
    use RefreshDatabase;

    private Assessment $assessment;

    private Student $student;

    private User $teacher;

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
        $subject = Subject::factory()->create([
            'grade_id' => $grade->id,
            'term_id' => $term->id,
            'max_score' => 100,
        ]);
        $type = AssessmentType::factory()->create(['school_id' => $school->id]);
        $this->assessment = Assessment::factory()->create([
            'subject_id' => $subject->id,
            'assessment_type_id' => $type->id,
            'max_score' => 100,
        ]);

        $this->student = Student::factory()->create(['school_id' => $school->id]);
        StudentEnrollment::factory()->create([
            'student_id' => $this->student->id,
            'section_id' => $section->id,
            'academic_year_id' => $year->id,
        ]);

        $this->teacher = User::factory()->role(UserRole::Teacher)->create(['school_id' => $school->id]);
        TeacherAssignment::factory()->create([
            'staff_id' => $this->teacher->id,
            'subject_id' => $subject->id,
            'section_id' => $section->id,
        ]);
    }

    /** الدرجة تصل رقماً قابلاً للكتابة في الحقل، لا بنيةً مركّبة. */
    public function test_score_is_returned_as_a_scalar_not_an_object(): void
    {
        GradeScore::factory()->create([
            'assessment_id' => $this->assessment->id,
            'student_id' => $this->student->id,
            'score' => 85,
        ]);

        Sanctum::actingAs($this->teacher);

        $row = $this->getJson("/api/assessments/{$this->assessment->id}/scores")
            ->assertOk()
            ->json('data.rows.0');

        $this->assertIsNotArray($row['score'], 'score must never be an object: the app writes it straight into the field.');
        $this->assertSame('85', (string) $row['score']);
    }

    /** «85.00» تُعاد «85»: الأستاذ كتب ٨٥ فلا يُعاد إليه بأصفارٍ لم يكتبها. */
    public function test_whole_scores_carry_no_trailing_decimals(): void
    {
        GradeScore::factory()->create([
            'assessment_id' => $this->assessment->id,
            'student_id' => $this->student->id,
            'score' => 85.00,
        ]);

        Sanctum::actingAs($this->teacher);

        $this->getJson("/api/assessments/{$this->assessment->id}/scores")
            ->assertOk()
            ->assertJsonPath('data.rows.0.score', '85');
    }

    /** والكسر الحقيقي يبقى كسراً: ٨٥٫٥ ليست ٨٥ ولا ٨٦. */
    public function test_fractional_scores_keep_their_fraction(): void
    {
        GradeScore::factory()->create([
            'assessment_id' => $this->assessment->id,
            'student_id' => $this->student->id,
            'score' => 85.5,
        ]);

        Sanctum::actingAs($this->teacher);

        $this->getJson("/api/assessments/{$this->assessment->id}/scores")
            ->assertOk()
            ->assertJsonPath('data.rows.0.score', '85.5');
    }

    /** خلية فارغة تبقى فارغة: `null` لا صفر ولا نصّ. */
    public function test_a_student_without_a_score_returns_null(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->getJson("/api/assessments/{$this->assessment->id}/scores")
            ->assertOk()
            ->assertJsonPath('data.rows.0.score', null)
            ->assertJsonPath('data.rows.0.record', null);
    }

    /** السجلّ كلّه يبقى متاحاً — في `record`، لا مدسوساً في `score`. */
    public function test_the_full_record_is_still_available_under_its_own_key(): void
    {
        $score = GradeScore::factory()->create([
            'assessment_id' => $this->assessment->id,
            'student_id' => $this->student->id,
            'score' => 85,
        ]);

        Sanctum::actingAs($this->teacher);

        $this->getJson("/api/assessments/{$this->assessment->id}/scores")
            ->assertOk()
            ->assertJsonPath('data.rows.0.record.id', $score->id)
            ->assertJsonPath('data.rows.0.record.student_id', $this->student->id);
    }

    /** الحفظ ثم القراءة: ما كتبه الأستاذ هو ما يعود إليه. */
    public function test_saving_then_reloading_returns_the_same_number(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson("/api/assessments/{$this->assessment->id}/scores", [
            'scores' => [['student_id' => $this->student->id, 'score' => 85]],
        ])->assertOk();

        $this->getJson("/api/assessments/{$this->assessment->id}/scores")
            ->assertOk()
            ->assertJsonPath('data.rows.0.score', '85');
    }

    /** وتعديل درجة قائمة يُبدّلها ولا يُنشئ ثانيةً. */
    public function test_editing_an_existing_score_overwrites_it(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson("/api/assessments/{$this->assessment->id}/scores", [
            'scores' => [['student_id' => $this->student->id, 'score' => 85]],
        ])->assertOk();

        $this->postJson("/api/assessments/{$this->assessment->id}/scores", [
            'scores' => [['student_id' => $this->student->id, 'score' => 90]],
        ])->assertOk();

        $this->assertSame(1, GradeScore::query()
            ->where('assessment_id', $this->assessment->id)
            ->where('student_id', $this->student->id)
            ->count());

        $this->getJson("/api/assessments/{$this->assessment->id}/scores")
            ->assertOk()
            ->assertJsonPath('data.rows.0.score', '90');
    }
}
