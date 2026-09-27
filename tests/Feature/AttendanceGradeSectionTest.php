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
use App\Models\TeacherAssignment;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * الحضور: الصفّ أوّلاً ثمّ الشعبة — والخادم يحرس القيد لا الشاشة وحدها.
 *
 * أسماء الشعب تتشابه بين الصفوف: «أ» في التاسع و«أ» في البكالوريا. فالشاشة
 * تُعطّل منتقي الشعبة حتى يُختار الصفّ، وتُسقط الشعبة عند تبديله. لكنّ ترتيباً
 * في الواجهة ليس حراسة: من نادى الـAPI مباشرةً يمرّ. وثمن الخطأ ليس رسالةً
 * على شاشة، بل إشعارُ غيابٍ يصل أباً عن ابنٍ كان حاضراً.
 *
 * ويحرس هذا الملفّ أيضاً أنّ نموذج الاستثناء لم يتغيّر: الحاضر لا سجلّ له.
 */
class AttendanceGradeSectionTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private AcademicYear $year;

    private Grade $ninth;

    private Grade $eleventh;

    private Section $ninthA;

    private Section $eleventhA;

    private Student $ninthStudent;

    private User $teacher;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->year = AcademicYear::factory()->current()->create(['school_id' => $this->school->id]);
        $term = Term::factory()->create(['academic_year_id' => $this->year->id]);

        // صفّان، وفي كلٍّ شعبة اسمها «أ» — وهو ما يُخطئ فيه المعلّم.
        $this->ninth = Grade::factory()->create(['school_id' => $this->school->id, 'name' => 'التاسع']);
        $this->eleventh = Grade::factory()->create(['school_id' => $this->school->id, 'name' => 'الحادي عشر']);

        $this->ninthA = Section::factory()->create([
            'grade_id' => $this->ninth->id,
            'academic_year_id' => $this->year->id,
            'name' => 'أ',
        ]);
        $this->eleventhA = Section::factory()->create([
            'grade_id' => $this->eleventh->id,
            'academic_year_id' => $this->year->id,
            'name' => 'أ',
        ]);

        $this->ninthStudent = Student::factory()->create(['school_id' => $this->school->id]);
        StudentEnrollment::factory()->create([
            'student_id' => $this->ninthStudent->id,
            'section_id' => $this->ninthA->id,
            'academic_year_id' => $this->year->id,
        ]);

        $subject = Subject::factory()->create([
            'grade_id' => $this->ninth->id,
            'term_id' => $term->id,
        ]);

        $this->teacher = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);
        TeacherAssignment::factory()->create([
            'staff_id' => $this->teacher->id,
            'subject_id' => $subject->id,
            'section_id' => $this->ninthA->id,
        ]);

        $this->admin = User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]);
    }

    // ------------------------------------------------------- grade → section

    /** شعب الصفّ وحدها تُعاد — لا قائمة شعبٍ عامّة. */
    public function test_sections_are_filtered_by_the_selected_grade(): void
    {
        Sanctum::actingAs($this->admin);

        $ids = $this->getJson("/api/sections?grade_id={$this->ninth->id}")
            ->assertOk()
            ->json('data.*.id');

        $this->assertSame([$this->ninthA->id], $ids);
        $this->assertNotContains($this->eleventhA->id, $ids);
    }

    /** وطلاب الشعبة المطلوبة وحدهم يظهرون في كشفها. */
    public function test_the_sheet_lists_only_students_of_that_grade_and_section(): void
    {
        $other = Student::factory()->create(['school_id' => $this->school->id]);
        StudentEnrollment::factory()->create([
            'student_id' => $other->id,
            'section_id' => $this->eleventhA->id,
            'academic_year_id' => $this->year->id,
        ]);

        Sanctum::actingAs($this->admin);

        $ids = $this->getJson("/api/sections/{$this->ninthA->id}/attendance")
            ->assertOk()
            ->json('data.rows.*.student.id');

        $this->assertSame([$this->ninthStudent->id], $ids);
    }

    /** صفٌّ وشعبةٌ من غيره: مرفوض عند القراءة. */
    public function test_reading_a_sheet_with_a_mismatched_grade_is_rejected(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson(
            "/api/sections/{$this->eleventhA->id}/attendance?grade_id={$this->ninth->id}",
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors('grade_id');
    }

    /** ومرفوض عند الحفظ — وهو الموضع الذي يُنتج إشعاراً خاطئاً. */
    public function test_saving_attendance_with_a_mismatched_grade_is_rejected(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/sections/{$this->eleventhA->id}/attendance", [
            'date' => now()->toDateString(),
            'grade_id' => $this->ninth->id,
            'records' => [
                ['student_id' => $this->ninthStudent->id, 'status' => 'absent'],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('grade_id');

        $this->assertDatabaseCount('attendance_records', 0);
    }

    /** والصفّ الصحيح يمرّ. */
    public function test_saving_attendance_with_the_matching_grade_is_accepted(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson("/api/sections/{$this->ninthA->id}/attendance", [
            'date' => now()->toDateString(),
            'grade_id' => $this->ninth->id,
            'records' => [
                ['student_id' => $this->ninthStudent->id, 'status' => 'absent'],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('attendance_records', [
            'student_id' => $this->ninthStudent->id,
            'section_id' => $this->ninthA->id,
            'status' => 'absent',
        ]);
    }

    /** وطالبٌ من شعبةٍ أخرى مرفوض ولو صحّ الصفّ. */
    public function test_a_student_from_another_section_is_rejected(): void
    {
        $outsider = Student::factory()->create(['school_id' => $this->school->id]);
        StudentEnrollment::factory()->create([
            'student_id' => $outsider->id,
            'section_id' => $this->eleventhA->id,
            'academic_year_id' => $this->year->id,
        ]);

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/sections/{$this->ninthA->id}/attendance", [
            'date' => now()->toDateString(),
            'records' => [['student_id' => $outsider->id, 'status' => 'absent']],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('records.0.student_id');
    }

    // ------------------------------------------------------- authorization

    /**
     * قراءة الكشف تبقى لكل من في المدرسة — صلاحية `view` كما كانت.
     *
     * شُدّدت مرّةً إلى `takeAttendance`، فتبيّن على الإنتاج أنّ الإسنادات صفر
     * فصار كل معلّم ممنوعاً من كل كشف. صلاحيات المعلّم القائمة تُصان، والحفظ
     * وحده هو المحروس.
     */
    public function test_a_teacher_may_still_read_the_sheet_of_any_section_in_their_school(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->getJson("/api/sections/{$this->eleventhA->id}/attendance")
            ->assertOk();
    }

    /**
     * لكنّه لا يسجّل فيها — وهذا هو الحدّ الحقيقي، وكان قائماً قبل هذا العمل.
     *
     * و٤٠٣ لا ٤٢٢: الصلاحية تُفحَص قبل البيانات، فلا تُفشي رسالة التحقّق «هل
     * الطالب مسجَّل في هذه الشعبة» لمن لا يحقّ له أن يعرف.
     */
    public function test_a_teacher_cannot_save_attendance_for_a_section_they_do_not_teach(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/sections/{$this->eleventhA->id}/attendance", [
            'date' => now()->toDateString(),
            'records' => [['student_id' => $this->ninthStudent->id, 'status' => 'absent']],
        ])->assertForbidden();

        $this->assertStringNotContainsString(
            'enrolled',
            $response->getContent(),
            'an unauthorized teacher must not learn enrolment facts from the error',
        );
    }

    /** والمعلّم يقرأ كشف شعبته هو. */
    public function test_a_teacher_reads_the_sheet_of_their_own_section(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->getJson("/api/sections/{$this->ninthA->id}/attendance")->assertOk();
    }

    /** والإدارة تقرأ كل شعب مدرستها. */
    public function test_an_admin_reads_any_section_in_their_school(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson("/api/sections/{$this->eleventhA->id}/attendance")->assertOk();
        $this->getJson("/api/sections/{$this->ninthA->id}/attendance")->assertOk();
    }

    /** ولا تُقرأ شعبةُ مدرسةٍ أخرى — عزل المدارس قائم. */
    public function test_a_section_of_another_school_is_not_readable(): void
    {
        $otherSchool = School::factory()->create();
        $otherYear = AcademicYear::factory()->current()->create(['school_id' => $otherSchool->id]);
        $otherGrade = Grade::factory()->create(['school_id' => $otherSchool->id]);
        $otherSection = Section::factory()->create([
            'grade_id' => $otherGrade->id,
            'academic_year_id' => $otherYear->id,
        ]);

        Sanctum::actingAs($this->admin);

        $this->getJson("/api/sections/{$otherSection->id}/attendance")->assertForbidden();
    }

    // -------------------------------------------- exception model preserved

    /** الحاضر لا سجلّ له — نموذج الاستثناء لم يتغيّر. */
    public function test_present_students_still_leave_no_record(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson("/api/sections/{$this->ninthA->id}/attendance", [
            'date' => now()->toDateString(),
            'grade_id' => $this->ninth->id,
            'records' => [['student_id' => $this->ninthStudent->id, 'status' => 'present']],
            'submit' => true,
        ])->assertOk();

        $this->assertDatabaseCount('attendance_records', 0);

        // والجلسة تبقى مأخوذة: يومٌ بلا غياب يومٌ سُجّل.
        $this->assertDatabaseHas('attendance_sessions', [
            'section_id' => $this->ninthA->id,
            'status' => 'submitted',
        ]);
    }

    /** والتصحيح إلى «حاضر» يحذف السجلّ بدل أن يكتب فوقه. */
    public function test_correcting_an_absence_to_present_deletes_the_record(): void
    {
        Sanctum::actingAs($this->teacher);
        $date = now()->toDateString();

        $this->postJson("/api/sections/{$this->ninthA->id}/attendance", [
            'date' => $date,
            'records' => [['student_id' => $this->ninthStudent->id, 'status' => 'absent']],
        ])->assertOk();

        $this->assertDatabaseCount('attendance_records', 1);

        $this->postJson("/api/sections/{$this->ninthA->id}/attendance", [
            'date' => $date,
            'records' => [['student_id' => $this->ninthStudent->id, 'status' => 'present']],
        ])->assertOk();

        $this->assertDatabaseCount('attendance_records', 0);
    }

    /** والحاضرون يُحسَبون بالطرح: طلاب الشعبة ناقص السجلّات. */
    public function test_present_count_is_derived_by_subtraction(): void
    {
        $second = Student::factory()->create(['school_id' => $this->school->id]);
        StudentEnrollment::factory()->create([
            'student_id' => $second->id,
            'section_id' => $this->ninthA->id,
            'academic_year_id' => $this->year->id,
        ]);

        Sanctum::actingAs($this->teacher);
        $date = now()->toDateString();

        $this->postJson("/api/sections/{$this->ninthA->id}/attendance", [
            'date' => $date,
            'records' => [['student_id' => $this->ninthStudent->id, 'status' => 'absent']],
        ])->assertOk();

        $this->getJson("/api/sections/{$this->ninthA->id}/attendance?date={$date}")
            ->assertOk()
            ->assertJsonPath('data.total_students', 2)
            ->assertJsonPath('data.summary.present', 1);
    }
}
