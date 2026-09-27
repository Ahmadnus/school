<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\Weekday;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\ScheduleSlot;
use App\Models\School;
use App\Models\SchoolDayHours;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\TeacherAvailability;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * واجهة الجدول: من يضبط ماذا، ومن يرى ماذا.
 *
 * الحدّ الجوهري المحروس هنا: **الأستاذ يضبط تفرّغه هو ولا يضبط تفرّغ غيره، ولا
 * يضبط دوام المعهد، ولا يولّد الجدول.** توليدٌ واحد يُعيد ترتيب أوقات الكادر
 * كلّه؛ وتفرّغٌ يعدّله زميلٌ يُسقط حصص صاحبه من الجدول بلا أن يعلم.
 */
class TimetableApiTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private AcademicYear $year;

    private Term $term;

    private Grade $grade;

    private Section $section;

    private Subject $subject;

    private User $admin;

    private User $ahmad;

    private User $sara;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create(['default_lesson_minutes' => 60]);
        $this->year = AcademicYear::factory()->current()->create(['school_id' => $this->school->id]);
        $this->term = Term::factory()->create([
            'academic_year_id' => $this->year->id,
            'is_current' => true,
        ]);
        $this->grade = Grade::factory()->create(['school_id' => $this->school->id, 'name' => 'التاسع']);
        $this->section = Section::factory()->create([
            'grade_id' => $this->grade->id,
            'academic_year_id' => $this->year->id,
            'name' => 'أ',
        ]);
        $this->subject = Subject::factory()->create([
            'grade_id' => $this->grade->id,
            'term_id' => $this->term->id,
            'name' => 'رياضيات',
        ]);

        $this->admin = User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]);
        $this->ahmad = User::factory()->role(UserRole::Teacher)->create([
            'school_id' => $this->school->id,
            'first_name' => 'أحمد',
        ]);
        $this->sara = User::factory()->role(UserRole::Teacher)->create([
            'school_id' => $this->school->id,
            'first_name' => 'سارة',
        ]);
    }

    private function openWeek(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/school/hours', [
            'days' => [Weekday::Saturday->value, Weekday::Sunday->value],
            'starts_at' => '10:00',
            'ends_at' => '18:00',
        ])->assertOk();
    }

    private function freeAllWeek(User $teacher): void
    {
        foreach ([Weekday::Saturday, Weekday::Sunday] as $day) {
            TeacherAvailability::factory()->create([
                'staff_id' => $teacher->id,
                'day_of_week' => $day,
                'starts_at' => '10:00',
                'ends_at' => '18:00',
            ]);
        }
    }

    // ------------------------------------------------------- school hours

    /** الإدارة تضبط دوام المعهد، ولكل يوم ساعاته. */
    public function test_an_admin_sets_school_working_hours_per_day(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/school/hours', [
            'days' => [Weekday::Saturday->value],
            'starts_at' => '10:00',
            'ends_at' => '18:00',
        ])->assertOk();

        $this->putJson('/api/school/hours', [
            'days' => [Weekday::Monday->value],
            'starts_at' => '12:00',
            'ends_at' => '18:00',
        ])->assertOk();

        $days = collect($this->getJson('/api/school/hours')->assertOk()->json('data.days'))
            ->keyBy('day');

        $this->assertTrue($days[Weekday::Saturday->value]['working']);
        $this->assertSame('10:00', $days[Weekday::Saturday->value]['starts_at']);
        $this->assertSame('12:00', $days[Weekday::Monday->value]['starts_at']);

        // والجمعة ليست في الأسبوع الدراسي أصلاً: `Weekday::schoolWeek()` تُسقطها
        // لأنها العطلة المطّردة هنا. فلا تُعرَض يوماً يمكن فتحه، ولا يستطيع
        // المولّد أن يضع فيها حصّة — وهو ما يحرسه اختبار «يوم مغلق» في
        // `TimetableGenerationTest`.
        $this->assertArrayNotHasKey(Weekday::Friday->value, $days->all());

        // والسبت إلى الخميس هي الأيّام المعروضة.
        $this->assertSame([6, 0, 1, 2, 3, 4], $days->keys()->all());
    }

    /** ويُغلق يوماً بحذف دوامه. */
    public function test_an_admin_closes_a_day(): void
    {
        $this->openWeek();

        $this->deleteJson('/api/school/hours', ['day' => Weekday::Saturday->value])
            ->assertOk();

        $days = collect($this->getJson('/api/school/hours')->json('data.days'))->keyBy('day');
        $this->assertFalse($days[Weekday::Saturday->value]['working']);
    }

    /** والأستاذ لا يضبط دوام المعهد. */
    public function test_a_teacher_cannot_set_school_hours(): void
    {
        Sanctum::actingAs($this->ahmad);

        $this->putJson('/api/school/hours', [
            'days' => [Weekday::Saturday->value],
            'starts_at' => '10:00',
            'ends_at' => '18:00',
        ])->assertForbidden();

        $this->assertSame(0, SchoolDayHours::query()->count());
    }

    /** طول الحصّة يُضبط مستقلّاً، ولا يُفترَض ٤٥. */
    public function test_the_lesson_length_is_configurable_and_not_forty_five(): void
    {
        Sanctum::actingAs($this->admin);

        $this->assertSame(
            60,
            $this->getJson('/api/school/hours')->assertOk()->json('data.lesson_minutes'),
        );

        $this->putJson('/api/school/lesson-minutes', ['minutes' => 90])->assertOk();

        $this->assertSame(
            90,
            $this->getJson('/api/school/hours')->json('data.lesson_minutes'),
        );
    }

    /** والشاشة تُخبَر صريحاً أنّ خانة التأشير ليست طول الحصّة. */
    public function test_the_response_separates_the_availability_grid_from_the_lesson_length(): void
    {
        Sanctum::actingAs($this->admin);

        $data = $this->getJson('/api/school/hours')->assertOk()->json('data');

        $this->assertSame(30, $data['availability_slot_minutes']);
        $this->assertSame(60, $data['lesson_minutes']);
        $this->assertNotSame($data['availability_slot_minutes'], $data['lesson_minutes']);
    }

    // ------------------------------------------------------- availability

    /** الأستاذ يضبط تفرّغه لكل يوم على حدة. */
    public function test_a_teacher_sets_their_own_availability_per_day(): void
    {
        Sanctum::actingAs($this->ahmad);

        $this->putJson("/api/users/{$this->ahmad->id}/availability", [
            'day' => Weekday::Saturday->value,
            'ranges' => [['starts_at' => '11:30', 'ends_at' => '13:00']],
        ])->assertOk();

        $this->putJson("/api/users/{$this->ahmad->id}/availability", [
            'day' => Weekday::Sunday->value,
            'ranges' => [['starts_at' => '08:00', 'ends_at' => '14:00']],
        ])->assertOk();

        $days = collect(
            $this->getJson("/api/users/{$this->ahmad->id}/availability")->assertOk()->json('data.days'),
        )->keyBy('day');

        $this->assertSame(
            [['starts_at' => '11:30', 'ends_at' => '13:00']],
            $days[Weekday::Saturday->value]['ranges'],
        );
        $this->assertSame(90, $days[Weekday::Saturday->value]['minutes']);
        $this->assertSame(360, $days[Weekday::Sunday->value]['minutes']);

        // ويومٌ لم يُؤشَّر غير متفرّغ فيه.
        $this->assertFalse($days[Weekday::Monday->value]['available']);
    }

    /** والخانات المتّصلة تُدمَج مدًى واحداً. */
    public function test_adjacent_slots_merge_into_one_range(): void
    {
        Sanctum::actingAs($this->ahmad);

        $this->putJson("/api/users/{$this->ahmad->id}/availability", [
            'day' => Weekday::Saturday->value,
            'ranges' => [
                ['starts_at' => '08:00', 'ends_at' => '08:30'],
                ['starts_at' => '08:30', 'ends_at' => '09:00'],
                ['starts_at' => '09:00', 'ends_at' => '09:30'],
                // قطعة منفصلة — تبقى منفصلة.
                ['starts_at' => '11:00', 'ends_at' => '12:00'],
            ],
        ])->assertOk();

        $days = collect(
            $this->getJson("/api/users/{$this->ahmad->id}/availability")->json('data.days'),
        )->keyBy('day');

        $this->assertSame([
            ['starts_at' => '08:00', 'ends_at' => '09:30'],
            ['starts_at' => '11:00', 'ends_at' => '12:00'],
        ], $days[Weekday::Saturday->value]['ranges']);
    }

    /** والحفظ استبدالٌ لا إضافة: إزالة خانةٍ يجب أن تكون ممكنة. */
    public function test_saving_replaces_the_day_rather_than_appending(): void
    {
        Sanctum::actingAs($this->ahmad);
        $url = "/api/users/{$this->ahmad->id}/availability";

        $this->putJson($url, [
            'day' => Weekday::Saturday->value,
            'ranges' => [['starts_at' => '08:00', 'ends_at' => '12:00']],
        ])->assertOk();

        $this->putJson($url, [
            'day' => Weekday::Saturday->value,
            'ranges' => [['starts_at' => '15:00', 'ends_at' => '16:00']],
        ])->assertOk();

        $days = collect($this->getJson($url)->json('data.days'))->keyBy('day');

        $this->assertSame(
            [['starts_at' => '15:00', 'ends_at' => '16:00']],
            $days[Weekday::Saturday->value]['ranges'],
        );
    }

    /** وقائمة فارغة تُفرغ اليوم. */
    public function test_an_empty_list_clears_the_day(): void
    {
        Sanctum::actingAs($this->ahmad);
        $url = "/api/users/{$this->ahmad->id}/availability";

        $this->putJson($url, [
            'day' => Weekday::Saturday->value,
            'ranges' => [['starts_at' => '08:00', 'ends_at' => '12:00']],
        ])->assertOk();

        $this->putJson($url, ['day' => Weekday::Saturday->value, 'ranges' => []])->assertOk();

        $this->assertSame(0, TeacherAvailability::query()->where('staff_id', $this->ahmad->id)->count());
    }

    /** وحدٌّ خارج شبكة نصف الساعة مرفوض. */
    public function test_a_boundary_off_the_thirty_minute_grid_is_rejected(): void
    {
        Sanctum::actingAs($this->ahmad);

        $this->putJson("/api/users/{$this->ahmad->id}/availability", [
            'day' => Weekday::Saturday->value,
            'ranges' => [['starts_at' => '08:10', 'ends_at' => '09:00']],
        ])->assertStatus(422);

        $this->assertSame(0, TeacherAvailability::query()->count());
    }

    /** **وأستاذٌ لا يضبط تفرّغ زميله.** */
    public function test_a_teacher_cannot_change_another_teachers_availability(): void
    {
        Sanctum::actingAs($this->ahmad);

        $this->putJson("/api/users/{$this->sara->id}/availability", [
            'day' => Weekday::Saturday->value,
            'ranges' => [['starts_at' => '08:00', 'ends_at' => '12:00']],
        ])->assertForbidden();

        $this->assertSame(0, TeacherAvailability::query()->count());
    }

    /** ولا يقرؤه. */
    public function test_a_teacher_cannot_read_another_teachers_availability(): void
    {
        Sanctum::actingAs($this->ahmad);

        $this->getJson("/api/users/{$this->sara->id}/availability")->assertForbidden();
    }

    /** والإدارة تقرأ وتعدّل تفرّغ الجميع — الكادر يُخبرها هاتفياً. */
    public function test_an_admin_may_read_and_set_any_teachers_availability(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/users/{$this->sara->id}/availability", [
            'day' => Weekday::Saturday->value,
            'ranges' => [['starts_at' => '10:00', 'ends_at' => '14:00']],
        ])->assertOk();

        $this->getJson("/api/users/{$this->sara->id}/availability")->assertOk();
    }

    /** ولا يُقرأ تفرّغ أستاذٍ في مدرسةٍ أخرى. */
    public function test_availability_is_isolated_between_schools(): void
    {
        $outsider = User::factory()->role(UserRole::Teacher)->create([
            'school_id' => School::factory()->create()->id,
        ]);

        Sanctum::actingAs($this->admin);

        $this->getJson("/api/users/{$outsider->id}/availability")->assertForbidden();
    }

    // ------------------------------------------------------- assignments

    /** عدد الحصص الأسبوعيّة يُضبط على الإسناد. */
    public function test_an_admin_sets_the_weekly_lesson_count_on_an_assignment(): void
    {
        $assignment = TeacherAssignment::factory()->create([
            'staff_id' => $this->ahmad->id,
            'subject_id' => $this->subject->id,
            'section_id' => $this->section->id,
            'lessons_per_week' => null,
        ]);

        Sanctum::actingAs($this->admin);

        $this->putJson("/api/teacher-assignments/{$assignment->id}", ['lessons_per_week' => 4])
            ->assertOk()
            ->assertJsonPath('data.lessons_per_week', 4)
            ->assertJsonPath('data.lessons_per_week_explicit', true);
    }

    /** وإفراغه يُرجعه إلى ما على المادة، لا إلى صفر. */
    public function test_clearing_the_count_falls_back_to_the_subject(): void
    {
        $this->subject->forceFill(['periods_per_week' => 3])->save();

        $assignment = TeacherAssignment::factory()->create([
            'staff_id' => $this->ahmad->id,
            'subject_id' => $this->subject->id,
            'section_id' => $this->section->id,
            'lessons_per_week' => 6,
        ]);

        Sanctum::actingAs($this->admin);

        $this->putJson("/api/teacher-assignments/{$assignment->id}", ['lessons_per_week' => null])
            ->assertOk()
            ->assertJsonPath('data.lessons_per_week', 3)
            ->assertJsonPath('data.lessons_per_week_explicit', false);
    }

    /** والأستاذ لا يعدّل عدد حصصه. */
    public function test_a_teacher_cannot_change_their_own_lesson_count(): void
    {
        $assignment = TeacherAssignment::factory()->create([
            'staff_id' => $this->ahmad->id,
            'subject_id' => $this->subject->id,
            'section_id' => $this->section->id,
        ]);

        Sanctum::actingAs($this->ahmad);

        $this->putJson("/api/teacher-assignments/{$assignment->id}", ['lessons_per_week' => 20])
            ->assertForbidden();
    }

    // ------------------------------------------------- analyze & generate

    /** التحليل يسبق التوليد، ويُعيد الأرقام والإسنادات للمراجعة. */
    public function test_analysis_reports_feasibility_with_numbers(): void
    {
        $this->openWeek();
        $this->freeAllWeek($this->ahmad);
        TeacherAssignment::factory()->create([
            'staff_id' => $this->ahmad->id,
            'subject_id' => $this->subject->id,
            'section_id' => $this->section->id,
            'lessons_per_week' => 4,
        ]);

        Sanctum::actingAs($this->admin);

        $data = $this->postJson('/api/timetable/analyze')->assertOk()->json('data');

        $this->assertTrue($data['feasible']);
        $this->assertSame(4, $data['lessons_required']);
        $this->assertSame([], $data['conflicts']);
        $this->assertSame(60, $data['lesson_minutes']);

        // وخطوة المراجعة: أستاذ ← مادة ← شعبة ← عدد.
        $this->assertSame([[
            'id' => $data['assignments'][0]['id'],
            'staff_id' => $this->ahmad->id,
            'teacher' => $this->ahmad->full_name,
            'subject_id' => $this->subject->id,
            'subject' => 'رياضيات',
            'section_id' => $this->section->id,
            'section' => 'التاسع - أ',
            'lessons_per_week' => 4,
            'explicit' => true,
        ]], $data['assignments']);

        // ولا حصّة كُتبت: التحليل يقرأ ولا يكتب.
        $this->assertSame(0, ScheduleSlot::query()->count());
    }

    /** والتحليل يسمّي سبب الاستحالة قبل أن يُضغط «ولّد». */
    public function test_analysis_names_the_blocking_teacher(): void
    {
        $this->openWeek();
        TeacherAssignment::factory()->create([
            'staff_id' => $this->ahmad->id,
            'subject_id' => $this->subject->id,
            'section_id' => $this->section->id,
            'lessons_per_week' => 4,
        ]);

        Sanctum::actingAs($this->admin);

        $data = $this->postJson('/api/timetable/analyze')->assertOk()->json('data');

        $this->assertFalse($data['feasible']);
        $this->assertSame('teacher_has_no_availability', $data['conflicts'][0]['code']);
        $this->assertStringContainsString('أحمد', $data['conflicts'][0]['message']);
    }

    /** التوليد الناجح يكتب الحصص ويُعيد الشبكة. */
    public function test_generation_writes_the_timetable(): void
    {
        $this->openWeek();
        $this->freeAllWeek($this->ahmad);
        TeacherAssignment::factory()->create([
            'staff_id' => $this->ahmad->id,
            'subject_id' => $this->subject->id,
            'section_id' => $this->section->id,
            'lessons_per_week' => 4,
        ]);

        Sanctum::actingAs($this->admin);

        $this->postJson('/api/timetable/generate')
            ->assertOk()
            ->assertJsonPath('data.status', 'generated')
            ->assertJsonPath('data.lessons_placed', 4);

        $this->assertSame(4, ScheduleSlot::query()->count());
    }

    /** والفاشل يُعيد ٤٢٢ وتقريراً، ولا يكتب حصّة. */
    public function test_an_impossible_timetable_returns_422_and_writes_nothing(): void
    {
        $this->openWeek();
        TeacherAvailability::factory()->create([
            'staff_id' => $this->ahmad->id,
            'day_of_week' => Weekday::Saturday,
            'starts_at' => '10:00',
            'ends_at' => '12:00',
        ]);
        TeacherAssignment::factory()->create([
            'staff_id' => $this->ahmad->id,
            'subject_id' => $this->subject->id,
            'section_id' => $this->section->id,
            'lessons_per_week' => 8,
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/timetable/generate')->assertStatus(422);

        $this->assertFalse($response->json('data.feasible'));
        $this->assertNotEmpty($response->json('data.conflicts'));
        $this->assertSame(0, ScheduleSlot::query()->count());
    }

    /** **والأستاذ لا يولّد الجدول ولا يحلّله.** */
    public function test_a_teacher_cannot_analyze_or_generate(): void
    {
        $this->openWeek();
        Sanctum::actingAs($this->ahmad);

        $this->postJson('/api/timetable/analyze')->assertForbidden();
        $this->postJson('/api/timetable/generate')->assertForbidden();
    }

    // ------------------------------------------------------------- views

    /** الأستاذ يرى جدوله هو من `timetable/mine`. */
    public function test_a_teacher_sees_their_own_timetable(): void
    {
        $this->generateFor($this->ahmad);

        Sanctum::actingAs($this->ahmad);

        $data = $this->getJson('/api/timetable/mine')->assertOk()->json('data');

        $this->assertSame($this->ahmad->id, $data['teacher']['id']);
        $this->assertSame(2, $data['total']);
        $this->assertSame(60, $data['lesson_minutes']);

        foreach ($data['slots'] as $slot) {
            $this->assertSame($this->ahmad->id, $slot['teacher']['id']);
            $this->assertSame('رياضيات', $slot['subject']['name']);
            $this->assertSame('التاسع', $slot['section']['grade']);
        }
    }

    /** ولا يرى جدول زميله. */
    public function test_a_teacher_cannot_see_another_teachers_timetable(): void
    {
        $this->generateFor($this->ahmad);

        Sanctum::actingAs($this->sara);

        $this->getJson("/api/timetable/teachers/{$this->ahmad->id}")->assertForbidden();
    }

    /** ولا الجدول الكامل. */
    public function test_a_teacher_cannot_see_the_whole_school_timetable(): void
    {
        $this->generateFor($this->ahmad);

        Sanctum::actingAs($this->ahmad);

        $this->getJson('/api/timetable/all')->assertForbidden();
    }

    /** والإدارة ترى الجدول كاملاً كشبكةً: صفوف أوقات × أعمدة أيّام. */
    public function test_an_admin_sees_the_whole_timetable_as_a_grid(): void
    {
        $this->generateFor($this->ahmad);

        Sanctum::actingAs($this->admin);

        $data = $this->getJson('/api/timetable/all')->assertOk()->json('data');

        $this->assertSame(2, $data['total']);
        $this->assertNotEmpty($data['rows']);
        $this->assertArrayHasKey('cells', $data['rows'][0]);
        $this->assertCount(6, $data['days']);

        // وكل حصّة تُعلن أنها مولَّدة — فيُعرَف ما تمحوه إعادة التوليد.
        foreach ($data['slots'] as $slot) {
            $this->assertTrue($slot['generated']);
        }
    }

    /** وترى جدول شعبة. */
    public function test_an_admin_sees_a_section_timetable(): void
    {
        $this->generateFor($this->ahmad);

        Sanctum::actingAs($this->admin);

        $this->getJson("/api/timetable/sections/{$this->section->id}")
            ->assertOk()
            ->assertJsonPath('data.section.id', $this->section->id)
            ->assertJsonPath('data.total', 2);
    }

    /** ومعلّم الشعبة يرى جدولها: يحتاج ما قبل حصّته وما بعدها. */
    public function test_a_teacher_of_the_section_sees_its_timetable(): void
    {
        $this->generateFor($this->ahmad);

        Sanctum::actingAs($this->ahmad);

        $this->getJson("/api/timetable/sections/{$this->section->id}")->assertOk();
    }

    /** ومن لا يدرّسها لا يراها. */
    public function test_a_teacher_outside_the_section_cannot_see_its_timetable(): void
    {
        $this->generateFor($this->ahmad);

        Sanctum::actingAs($this->sara);

        $this->getJson("/api/timetable/sections/{$this->section->id}")->assertForbidden();
    }

    /** والترشيح باليوم يعمل. */
    public function test_the_timetable_can_be_filtered_by_day(): void
    {
        $this->generateFor($this->ahmad);

        Sanctum::actingAs($this->admin);

        $saturday = $this->getJson('/api/timetable/all?day_of_week='.Weekday::Saturday->value)
            ->assertOk()
            ->json('data.slots');

        $this->assertNotEmpty($saturday);

        foreach ($saturday as $slot) {
            $this->assertSame(Weekday::Saturday->value, $slot['day_of_week']);
        }
    }

    // ------------------------------------------------------ manual editing

    /** الإدارة تنقل حصّة إلى موضعٍ صالح. */
    public function test_an_admin_moves_a_lesson_to_a_valid_slot(): void
    {
        $this->generateFor($this->ahmad);

        $slot = ScheduleSlot::query()->where('day_of_week', Weekday::Saturday)->firstOrFail();

        Sanctum::actingAs($this->admin);

        $this->putJson("/api/timetable/slots/{$slot->id}", [
            'day_of_week' => Weekday::Saturday->value,
            'starts_at' => '16:00',
            'ends_at' => '17:00',
        ])
            ->assertOk()
            ->assertJsonPath('data.starts_at', '16:00');
    }

    /** ولا تُحفَظ حصّة خارج دوام المعهد ولو كان الفاعل مديراً. */
    public function test_even_an_admin_cannot_move_a_lesson_outside_school_hours(): void
    {
        $this->generateFor($this->ahmad);

        $slot = ScheduleSlot::query()->firstOrFail();
        $before = (string) $slot->starts_at;

        Sanctum::actingAs($this->admin);

        $this->putJson("/api/timetable/slots/{$slot->id}", [
            'day_of_week' => Weekday::Saturday->value,
            'starts_at' => '19:00',
            'ends_at' => '20:00',
        ])
            ->assertStatus(422)
            ->assertJsonPath('data.conflicts.0.code', 'outside_school_hours');

        $this->assertSame($before, (string) $slot->fresh()->starts_at);
    }

    /** ولا تعارضاً مع حصّة أخرى للشعبة. */
    public function test_a_move_that_double_books_the_section_is_rejected(): void
    {
        $this->generateFor($this->ahmad);

        $slots = ScheduleSlot::query()->orderBy('id')->get();
        $target = $slots->first();
        $other = $slots->last();

        Sanctum::actingAs($this->admin);

        $codes = array_column(
            $this->putJson("/api/timetable/slots/{$target->id}", [
                'day_of_week' => $other->day_of_week->value,
                'starts_at' => substr((string) $other->starts_at, 0, 5),
                'ends_at' => substr((string) $other->ends_at, 0, 5),
            ])->assertStatus(422)->json('data.conflicts'),
            'code',
        );

        $this->assertNotEmpty(array_intersect(
            ['section_double_booked', 'teacher_double_booked'],
            $codes,
        ));
    }

    /** والأستاذ لا ينقل حصّة. */
    public function test_a_teacher_cannot_move_a_lesson(): void
    {
        $this->generateFor($this->ahmad);

        $slot = ScheduleSlot::query()->firstOrFail();

        Sanctum::actingAs($this->ahmad);

        $this->putJson("/api/timetable/slots/{$slot->id}", [
            'day_of_week' => Weekday::Saturday->value,
            'starts_at' => '16:00',
            'ends_at' => '17:00',
        ])->assertForbidden();
    }

    /** وحصّةُ مدرسةٍ أخرى لا تُنقَل. */
    public function test_a_slot_of_another_school_cannot_be_moved(): void
    {
        $otherSchool = School::factory()->create();
        $otherYear = AcademicYear::factory()->current()->create(['school_id' => $otherSchool->id]);
        $otherTerm = Term::factory()->create(['academic_year_id' => $otherYear->id]);
        $otherGrade = Grade::factory()->create(['school_id' => $otherSchool->id]);
        $otherSection = Section::factory()->create([
            'grade_id' => $otherGrade->id,
            'academic_year_id' => $otherYear->id,
        ]);
        $otherSubject = Subject::factory()->create([
            'grade_id' => $otherGrade->id,
            'term_id' => $otherTerm->id,
        ]);
        $foreign = ScheduleSlot::factory()->create([
            'section_id' => $otherSection->id,
            'term_id' => $otherTerm->id,
            'subject_id' => $otherSubject->id,
        ]);

        Sanctum::actingAs($this->admin);

        $this->putJson("/api/timetable/slots/{$foreign->id}", [
            'day_of_week' => Weekday::Saturday->value,
            'starts_at' => '16:00',
            'ends_at' => '17:00',
        ])->assertForbidden();
    }

    // ------------------------------------------------------- regeneration

    /** حذف الجدول المولَّد يُبقي ما بُني يدويّاً. */
    public function test_clearing_the_generated_timetable_spares_manual_slots(): void
    {
        $this->generateFor($this->ahmad);

        $manual = ScheduleSlot::factory()->create([
            'section_id' => $this->section->id,
            'term_id' => $this->term->id,
            'subject_id' => $this->subject->id,
            'staff_id' => null,
            'timetable_run_id' => null,
            'day_of_week' => Weekday::Sunday,
            'starts_at' => '17:00',
            'ends_at' => '18:00',
            'period_number' => 99,
        ]);

        Sanctum::actingAs($this->admin);

        $this->deleteJson('/api/timetable/generated')->assertOk();

        $this->assertSame(0, ScheduleSlot::query()->generated()->count());
        $this->assertDatabaseHas('schedule_slots', ['id' => $manual->id]);
    }

    /** وآخر محاولة تبقى مقروءة بعد إغلاق الشاشة. */
    public function test_the_latest_run_is_readable_afterwards(): void
    {
        $this->generateFor($this->ahmad);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/timetable/latest')
            ->assertOk()
            ->assertJsonPath('data.status', 'generated')
            ->assertJsonPath('data.lessons_placed', 2);
    }

    /** ومعهدٌ بلا جدول بعد يقول ذلك بلا خطأ. */
    public function test_no_run_yet_is_reported_plainly(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/timetable/latest')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    /** يولّد جدولاً صغيراً: حصّتان لأحمد على يومين. */
    private function generateFor(User $teacher): void
    {
        $this->openWeek();
        $this->freeAllWeek($teacher);

        TeacherAssignment::factory()->create([
            'staff_id' => $teacher->id,
            'subject_id' => $this->subject->id,
            'section_id' => $this->section->id,
            'lessons_per_week' => 2,
        ]);

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/timetable/generate')->assertOk();
    }
}
