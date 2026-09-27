<?php

namespace Tests\Feature;

use App\Enums\NotificationApp;
use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\Grade;
use App\Models\GradeScore;
use App\Models\Guardian;
use App\Models\Notification;
use App\Models\School;
use App\Models\SchoolNotificationSetting;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentGuardian;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\Term;
use App\Models\User;
use App\Services\SubjectGrade;
use App\Services\TasmiSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * التسميع: بعض الطلاب لا كلّهم، ولا أثر على من لم يشترك.
 *
 * ما تحرسه هذه الاختبارات أنّ التسميع **لم** يصر نظاماً ثانياً: لا جدول جديد،
 * ولا حساب جديد، ولا إشعار جديد — نوعُ تقييم يجلس على ما هو قائم. وأهمّها أنّ
 * الستّة عشر الذين لم يُسمَّعوا لا يُكتب لهم شيء: لا صفر، ولا `null`، ولا صفّ
 * فارغ يجعل التسميع يبدو ناقصاً.
 */
class TasmiTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private AcademicYear $year;

    private Grade $grade;

    private Section $section;

    private Section $otherSection;

    private Subject $subject;

    private User $teacher;

    private User $admin;

    /** @var array<string, Student> */
    private array $students = [];

    private User $guardianUser;

    private User $otherGuardianUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->year = AcademicYear::factory()->current()->create(['school_id' => $this->school->id]);
        $term = Term::factory()->create(['academic_year_id' => $this->year->id]);
        $this->grade = Grade::factory()->create(['school_id' => $this->school->id, 'name' => 'التاسع']);

        $this->section = Section::factory()->create([
            'grade_id' => $this->grade->id,
            'academic_year_id' => $this->year->id,
            'name' => 'أ',
        ]);
        $this->otherSection = Section::factory()->create([
            'grade_id' => $this->grade->id,
            'academic_year_id' => $this->year->id,
            'name' => 'ب',
        ]);

        $this->subject = Subject::factory()->create([
            'grade_id' => $this->grade->id,
            'term_id' => $term->id,
            'name' => 'قرآن',
        ]);

        // أربعة طلاب: A و C و F سيشاركون، و B لا.
        foreach (['A', 'B', 'C', 'F'] as $label) {
            $student = Student::factory()->create([
                'school_id' => $this->school->id,
                'first_name' => 'طالب'.$label,
            ]);
            StudentEnrollment::factory()->create([
                'student_id' => $student->id,
                'section_id' => $this->section->id,
                'academic_year_id' => $this->year->id,
            ]);
            $this->students[$label] = $student;
        }

        $this->teacher = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);
        TeacherAssignment::factory()->create([
            'staff_id' => $this->teacher->id,
            'subject_id' => $this->subject->id,
            'section_id' => $this->section->id,
        ]);

        $this->admin = User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]);

        $this->guardianUser = $this->linkGuardian($this->students['A']);
        $this->otherGuardianUser = $this->linkGuardian($this->students['B']);
    }

    private function linkGuardian(Student $student, bool $signedIn = true): User
    {
        $user = $signedIn
            ? User::factory()->role(UserRole::Guardian)->create(['school_id' => $this->school->id])
            : null;

        $guardian = Guardian::factory()->create([
            'school_id' => $this->school->id,
            'user_id' => $user?->id,
        ]);
        StudentGuardian::factory()->create([
            'student_id' => $student->id,
            'guardian_id' => $guardian->id,
        ]);

        return $user ?? new User;
    }

    private function payload(array $entries, array $overrides = []): array
    {
        return [
            'subject_id' => $this->subject->id,
            'section_id' => $this->section->id,
            'held_on' => '2026-09-27',
            'entries' => $entries,
            ...$overrides,
        ];
    }

    // --------------------------------------------------------------- creation

    /** ينشئ جلسة ويكتب درجات المختارين وحدهم. */
    public function test_it_records_marks_for_selected_students_only(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/tasmi', $this->payload([
            ['student_id' => $this->students['A']->id, 'score' => 90],
            ['student_id' => $this->students['C']->id, 'score' => 85],
            ['student_id' => $this->students['F']->id, 'score' => 70],
        ]))->assertOk()->assertJsonPath('data.saved', 3);

        $this->assertSame(3, GradeScore::query()->count());

        foreach (['A', 'C', 'F'] as $label) {
            $this->assertDatabaseHas('grades_scores', [
                'student_id' => $this->students[$label]->id,
            ]);
        }
    }

    /** ومن لم يُختَر **لا صفّ له**: لا صفر ولا فراغ ولا سجلّ. */
    public function test_unselected_students_get_no_record_at_all(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/tasmi', $this->payload([
            ['student_id' => $this->students['A']->id, 'score' => 90],
        ]))->assertOk();

        $this->assertDatabaseMissing('grades_scores', [
            'student_id' => $this->students['B']->id,
        ]);
        $this->assertSame(1, GradeScore::query()->count());
    }

    /** طالبٌ بلا درجة ليس مشاركاً: يُرفَض الإدخال بلا علامة. */
    public function test_an_entry_without_a_mark_is_rejected(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/tasmi', $this->payload([
            ['student_id' => $this->students['A']->id, 'score' => null],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('entries.0.score');
    }

    /** ويُستعمل نوع «تسميع» — يُنشأ مرّة واحدة للمدرسة. */
    public function test_it_reuses_one_recitation_assessment_type_per_school(): void
    {
        Sanctum::actingAs($this->teacher);

        foreach (['2026-09-27', '2026-09-28'] as $date) {
            $this->postJson('/api/tasmi', $this->payload(
                [['student_id' => $this->students['A']->id, 'score' => 90]],
                ['held_on' => $date],
            ))->assertOk();
        }

        $this->assertSame(1, AssessmentType::query()
            ->where('school_id', $this->school->id)
            ->where('name', 'تسميع')
            ->count());

        // جلستان لتاريخين، لا واحدة ولا أربع.
        $this->assertSame(2, Assessment::query()->count());
    }

    /** وحفظٌ ثانٍ في اليوم نفسه تعديلٌ للجلسة لا جلسةٌ ثانية. */
    public function test_saving_twice_on_the_same_day_edits_the_same_session(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/tasmi', $this->payload([
            ['student_id' => $this->students['A']->id, 'score' => 90],
        ]))->assertOk();

        $this->postJson('/api/tasmi', $this->payload([
            ['student_id' => $this->students['A']->id, 'score' => 90],
            ['student_id' => $this->students['C']->id, 'score' => 60],
        ]))->assertOk();

        $this->assertSame(1, Assessment::query()->count());
        $this->assertSame(2, GradeScore::query()->count());
    }

    /** وإزالة طالبٍ من الجلسة تحذف صفَّه بدل أن تتركه بصفر. */
    public function test_removing_a_student_deletes_their_record(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/tasmi', $this->payload([
            ['student_id' => $this->students['A']->id, 'score' => 90],
            ['student_id' => $this->students['C']->id, 'score' => 60],
        ]))->assertOk();

        $this->postJson('/api/tasmi', $this->payload([
            ['student_id' => $this->students['A']->id, 'score' => 90],
        ]))->assertOk()->assertJsonPath('data.removed', 1);

        $this->assertDatabaseMissing('grades_scores', [
            'student_id' => $this->students['C']->id,
        ]);
    }

    /** وتعديل درجة قائمة يُبدّلها. */
    public function test_editing_an_existing_mark_updates_it(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/tasmi', $this->payload([
            ['student_id' => $this->students['A']->id, 'score' => 90],
        ]))->assertOk();

        $this->postJson('/api/tasmi', $this->payload([
            ['student_id' => $this->students['A']->id, 'score' => 75],
        ]))->assertOk();

        $this->assertSame(1, GradeScore::query()->count());
        $this->assertSame(
            '75',
            TasmiSession::plain(GradeScore::query()->first()->score),
        );
    }

    // --------------------------------------------------------------- roster

    /** شبكة الاختيار: طلاب الشعبة، ومن منهم مؤشَّر. */
    public function test_the_roster_marks_who_already_participated(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/tasmi', $this->payload([
            ['student_id' => $this->students['A']->id, 'score' => 90],
        ]))->assertOk();

        $rows = $this->getJson('/api/tasmi/roster?'.http_build_query([
            'subject_id' => $this->subject->id,
            'section_id' => $this->section->id,
            'held_on' => '2026-09-27',
        ]))->assertOk()->json('data.rows');

        $this->assertCount(4, $rows);

        $byId = collect($rows)->keyBy('student.id');
        $this->assertTrue($byId[$this->students['A']->id]['participating']);
        $this->assertSame('90', $byId[$this->students['A']->id]['score']);
        $this->assertFalse($byId[$this->students['B']->id]['participating']);
        $this->assertNull($byId[$this->students['B']->id]['score']);
    }

    /** والعرض لا يُنشئ جلسة: القراءة لا تكتب. */
    public function test_viewing_the_roster_creates_no_session(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->getJson('/api/tasmi/roster?'.http_build_query([
            'subject_id' => $this->subject->id,
            'section_id' => $this->section->id,
        ]))->assertOk()->assertJsonPath('data.assessment_id', null);

        $this->assertSame(0, Assessment::query()->count());
    }

    /** الدرجة تُعاد رقماً مجرَّداً — نفس عقد شبكة الدرجات، بلا كائن. */
    public function test_the_roster_returns_plain_numeric_marks(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/tasmi', $this->payload([
            ['student_id' => $this->students['A']->id, 'score' => 85],
        ]))->assertOk();

        $rows = $this->getJson('/api/tasmi/roster?'.http_build_query([
            'subject_id' => $this->subject->id,
            'section_id' => $this->section->id,
            'held_on' => '2026-09-27',
        ]))->assertOk()->json('data.rows');

        $score = collect($rows)->firstWhere('student.id', $this->students['A']->id)['score'];

        $this->assertIsNotArray($score);
        $this->assertSame('85', $score);
    }

    // ---------------------------------------------------------- validation

    /** شعبةٌ من صفٍّ آخر مرفوضة. */
    public function test_a_section_from_another_grade_is_rejected(): void
    {
        $otherGrade = Grade::factory()->create(['school_id' => $this->school->id]);
        $foreign = Section::factory()->create([
            'grade_id' => $otherGrade->id,
            'academic_year_id' => $this->year->id,
        ]);

        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/tasmi', $this->payload(
            [],
            ['section_id' => $foreign->id],
        ))
            ->assertStatus(422)
            ->assertJsonValidationErrors('section_id');
    }

    /** وطالبٌ من شعبةٍ أخرى مرفوض. */
    public function test_a_student_from_another_section_is_rejected(): void
    {
        $outsider = Student::factory()->create(['school_id' => $this->school->id]);
        StudentEnrollment::factory()->create([
            'student_id' => $outsider->id,
            'section_id' => $this->otherSection->id,
            'academic_year_id' => $this->year->id,
        ]);

        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/tasmi', $this->payload([
            ['student_id' => $outsider->id, 'score' => 90],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('entries.0.student_id');
    }

    /** ودرجةٌ فوق الحدّ مرفوضة. */
    public function test_a_mark_above_the_maximum_is_rejected(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/tasmi', $this->payload([
            ['student_id' => $this->students['A']->id, 'score' => 120],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('entries.0.score');
    }

    /** وسالبة. */
    public function test_a_negative_mark_is_rejected(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/tasmi', $this->payload([
            ['student_id' => $this->students['A']->id, 'score' => -5],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('entries.0.score');
    }

    // ------------------------------------------------------- authorization

    /** معلّمٌ لا تُسنَد إليه المادة لا يسمّع فيها. */
    public function test_a_teacher_without_the_assignment_is_forbidden(): void
    {
        $stranger = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);

        Sanctum::actingAs($stranger);

        $this->postJson('/api/tasmi', $this->payload([
            ['student_id' => $this->students['A']->id, 'score' => 90],
        ]))->assertForbidden();

        $this->assertSame(0, Assessment::query()->count());
    }

    /** والإدارة تسمّع في مدرستها. */
    public function test_an_admin_may_record_recitation(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/tasmi', $this->payload([
            ['student_id' => $this->students['A']->id, 'score' => 90],
        ]))->assertOk();
    }

    /** ولا يسمّع أحدٌ في مدرسةٍ أخرى — عزل المدارس قائم. */
    public function test_a_subject_of_another_school_is_rejected(): void
    {
        $otherSchool = School::factory()->create();
        $otherYear = AcademicYear::factory()->current()->create(['school_id' => $otherSchool->id]);
        $otherTerm = Term::factory()->create(['academic_year_id' => $otherYear->id]);
        $otherGrade = Grade::factory()->create(['school_id' => $otherSchool->id]);
        $foreignSubject = Subject::factory()->create([
            'grade_id' => $otherGrade->id,
            'term_id' => $otherTerm->id,
        ]);

        Sanctum::actingAs($this->admin);

        $this->postJson('/api/tasmi', $this->payload(
            [],
            ['subject_id' => $foreignSubject->id],
        ))
            ->assertStatus(422)
            ->assertJsonValidationErrors('subject_id');
    }

    /** وليّ الأمر لا يكتب تسميعاً. */
    public function test_a_guardian_cannot_record_recitation(): void
    {
        Sanctum::actingAs($this->guardianUser);

        $this->postJson('/api/tasmi', $this->payload([
            ['student_id' => $this->students['A']->id, 'score' => 90],
        ]))->assertForbidden();
    }

    // ------------------------------------------------------- notifications

    /** وليّ الأمر يصله إشعار بدرجة ابنه، فيه المادة والدرجة والتاريخ. */
    public function test_the_guardian_is_notified_with_subject_mark_and_date(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/tasmi', $this->payload([
            ['student_id' => $this->students['A']->id, 'score' => 85],
        ]))->assertOk()->assertJsonPath('data.notified', 1);

        $notification = Notification::query()
            ->where('user_id', $this->guardianUser->id)
            ->where('type', 'tasmi_recorded')
            ->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('قرآن', $notification->title);
        $this->assertStringContainsString('85', $notification->body);
        $this->assertStringContainsString('100', $notification->body);
        $this->assertSame($this->students['A']->id, $notification->ref_id);
    }

    /** ولا يرى وليُّ أمرٍ درجةَ طالبٍ ليس ابنه. */
    public function test_a_guardian_never_receives_another_students_mark(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/tasmi', $this->payload([
            ['student_id' => $this->students['A']->id, 'score' => 85],
        ]))->assertOk();

        $this->assertSame(0, Notification::query()
            ->where('user_id', $this->otherGuardianUser->id)
            ->where('type', 'tasmi_recorded')
            ->count());
    }

    /** ومن لم يشترك لا يصل أهله شيء. */
    public function test_guardians_of_unselected_students_are_not_notified(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/tasmi', $this->payload([
            ['student_id' => $this->students['C']->id, 'score' => 85],
        ]))->assertOk();

        $this->assertSame(0, Notification::query()->where('type', 'tasmi_recorded')->count());
    }

    /** وحفظٌ ثانٍ بلا تعديل لا يُعيد الإشعار. */
    public function test_saving_again_without_a_change_sends_no_second_notification(): void
    {
        Sanctum::actingAs($this->teacher);

        $body = $this->payload([['student_id' => $this->students['A']->id, 'score' => 85]]);

        $this->postJson('/api/tasmi', $body)->assertOk()->assertJsonPath('data.notified', 1);
        $this->postJson('/api/tasmi', $body)->assertOk()->assertJsonPath('data.notified', 0);

        $this->assertSame(1, Notification::query()->where('type', 'tasmi_recorded')->count());
    }

    /** وتصحيح الدرجة يُشعر ثانيةً — الخبر تغيّر. */
    public function test_correcting_a_mark_notifies_again(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/tasmi', $this->payload([
            ['student_id' => $this->students['A']->id, 'score' => 85],
        ]))->assertOk();

        $this->postJson('/api/tasmi', $this->payload([
            ['student_id' => $this->students['A']->id, 'score' => 95],
        ]))->assertOk()->assertJsonPath('data.notified', 1);

        $this->assertSame(2, Notification::query()->where('type', 'tasmi_recorded')->count());
    }

    /** ومفتاح المدرسة يُطاع: إن أُغلق لم يُرسل شيء. */
    public function test_the_school_switch_is_respected(): void
    {
        SchoolNotificationSetting::factory()->create([
            'school_id' => $this->school->id,
            'app' => NotificationApp::Guardian,
            'key' => 'tasmi_recorded',
            'group' => 'academic',
            'is_enabled' => false,
        ]);

        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/tasmi', $this->payload([
            ['student_id' => $this->students['A']->id, 'score' => 85],
        ]))->assertOk()->assertJsonPath('data.notified', 0);

        $this->assertSame(0, Notification::query()->where('type', 'tasmi_recorded')->count());
    }

    /** ووليّ أمرٍ لم يسجّل دخوله يُعلَن ولا يُحسَب مُرسَلاً. */
    public function test_a_guardian_who_never_signed_in_is_reported_not_counted_as_sent(): void
    {
        $student = $this->students['F'];
        $guardian = Guardian::factory()->create([
            'school_id' => $this->school->id,
            'user_id' => null,
        ]);
        StudentGuardian::factory()->create([
            'student_id' => $student->id,
            'guardian_id' => $guardian->id,
        ]);

        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/tasmi', $this->payload([
            ['student_id' => $student->id, 'score' => 85],
        ]))
            ->assertOk()
            ->assertJsonPath('data.notified', 0)
            ->assertJsonCount(1, 'data.not_signed_in')
            ->assertJsonCount(0, 'data.without_guardian');
    }

    /** وطالبٌ بلا وليّ أمر مُدخَل يُعلَن في خانته — الحالتان لا تُخلَطان. */
    public function test_a_student_with_no_guardian_is_reported_separately(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/tasmi', $this->payload([
            ['student_id' => $this->students['C']->id, 'score' => 85],
        ]))
            ->assertOk()
            ->assertJsonPath('data.notified', 0)
            ->assertJsonCount(0, 'data.not_signed_in')
            ->assertJsonCount(1, 'data.without_guardian');
    }

    // ------------------------------------------------------ grade integration

    /** من لم يُسمَّع لا تنزل علامته: النوع الفارغ يخرج من الحساب. */
    public function test_a_student_who_did_not_participate_keeps_their_average(): void
    {
        $examType = AssessmentType::factory()->create([
            'school_id' => $this->school->id,
            'name' => 'نهائي',
            'weight_percent' => null,
        ]);
        $exam = Assessment::factory()->create([
            'subject_id' => $this->subject->id,
            'assessment_type_id' => $examType->id,
            'name' => 'الامتحان النهائي',
            'max_score' => 100,
            // صفر = «بلا وزنٍ خاصّ، خُذ وزن النوع» — كما تفعل جلسة التسميع.
            // وزنٌ مكتوب على الورقة نفسها يعلو على وزن نوعها (سلوك موثَّق في
            // `SubjectGrade`)، فلو تُرك افتراض المصنع (٢٠) لقُوبل نوعٌ موزون
            // بنوعٍ غير موزون، وهي مقارنة لا تقيس ما يقصده الاختبار.
            'weight_percent' => 0,
        ]);

        Sanctum::actingAs($this->teacher);

        // كلا الطالبين أخذ ٨٠ في النهائي؛ A وحده سُمِّع وأخذ ٤٠.
        foreach (['A', 'B'] as $label) {
            GradeScore::factory()->create([
                'assessment_id' => $exam->id,
                'student_id' => $this->students[$label]->id,
                'score' => 80,
            ]);
        }

        $this->postJson('/api/tasmi', $this->payload([
            ['student_id' => $this->students['A']->id, 'score' => 40],
        ]))->assertOk();

        $assessments = Assessment::query()
            ->where('subject_id', $this->subject->id)
            ->with('type')
            ->get();

        $percentFor = function (Student $student) use ($assessments) {
            $scores = GradeScore::query()
                ->where('student_id', $student->id)
                ->get()
                ->keyBy('assessment_id');

            return SubjectGrade::percent($assessments, $scores);
        };

        // B لم يُسمَّع: نوع التسميع يخرج من قسمته، فيبقى على ٨٠.
        $this->assertSame(80.0, $percentFor($this->students['B']));

        // A سُمِّع: النوعان يتقاسمان المئة بالتساوي، (80+40)/2 = 60.
        $this->assertSame(60.0, $percentFor($this->students['A']));
    }

    // ------------------------------------------------------------- listing

    /** الجلسات تُسرَد بعدد مشاركيها. */
    public function test_sessions_are_listed_with_their_participant_count(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/tasmi', $this->payload([
            ['student_id' => $this->students['A']->id, 'score' => 90],
            ['student_id' => $this->students['C']->id, 'score' => 80],
        ]))->assertOk();

        $this->getJson("/api/tasmi?section_id={$this->section->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.participants_count', 2)
            ->assertJsonPath('data.0.section.name', 'أ')
            ->assertJsonPath('data.0.subject.name', 'قرآن');
    }

    /** وجلسةٌ واحدة تُعرَض بمشاركيها ودرجاتهم. */
    public function test_a_single_session_shows_its_participants(): void
    {
        Sanctum::actingAs($this->teacher);

        $id = $this->postJson('/api/tasmi', $this->payload([
            ['student_id' => $this->students['A']->id, 'score' => 90],
        ]))->assertOk()->json('data.id');

        $this->getJson("/api/tasmi/{$id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.score', '90');
    }

    /** وتقييمٌ ليس تسميعاً لا يُقرأ من مسار التسميع. */
    public function test_a_non_recitation_assessment_is_not_reachable_here(): void
    {
        $type = AssessmentType::factory()->create([
            'school_id' => $this->school->id,
            'name' => 'نهائي',
        ]);
        $exam = Assessment::factory()->create([
            'subject_id' => $this->subject->id,
            'assessment_type_id' => $type->id,
        ]);

        Sanctum::actingAs($this->teacher);

        $this->getJson("/api/tasmi/{$exam->id}")->assertNotFound();
    }
}
