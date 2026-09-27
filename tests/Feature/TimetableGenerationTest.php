<?php

namespace Tests\Feature;

use App\Enums\TimetableRunStatus;
use App\Enums\UserRole;
use App\Enums\Weekday;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\ScheduleSlot;
use App\Models\School;
use App\Models\SchoolDayHours;
use App\Models\Section;
use App\Models\SectionDayHours;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\TeacherAvailability;
use App\Models\Term;
use App\Models\TimetableRun;
use App\Models\User;
use App\Services\Timetable\TimeRange;
use App\Services\Timetable\TimetableAnalyzer;
use App\Services\Timetable\TimetableConstraints;
use App\Services\Timetable\TimetableGenerator;
use App\Services\Timetable\TimetableValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * محرّك توليد الجدول: القيود الصلبة، والاستحالة المُعلَنة.
 *
 * ما تحرسه هذه الاختبارات ليس «أن يخرج جدول»، بل **ألاّ يخرج جدولٌ كاذب**:
 * جدولٌ فيه أستاذ في مكانين، أو حصّة خارج الدوام، أو ٣٩ حصّة من ٤٠ يُقال عنها
 * «تمّ» — كلّها أسوأ من رفضٍ صريح، لأنها لا تُكتشف إلاّ بعد أسبوع من الدراسة.
 */
class TimetableGenerationTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Term $term;

    private AcademicYear $year;

    private Grade $grade;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create(['default_lesson_minutes' => 60]);
        $this->year = AcademicYear::factory()->current()->create(['school_id' => $this->school->id]);
        $this->term = Term::factory()->create(['academic_year_id' => $this->year->id]);
        $this->grade = Grade::factory()->create(['school_id' => $this->school->id]);
    }

    // --------------------------------------------------------------- helpers

    private function section(string $name = 'أ'): Section
    {
        return Section::factory()->create([
            'grade_id' => $this->grade->id,
            'academic_year_id' => $this->year->id,
            'name' => $name,
        ]);
    }

    private function subject(string $name = 'رياضيات'): Subject
    {
        return Subject::factory()->create([
            'grade_id' => $this->grade->id,
            'term_id' => $this->term->id,
            'name' => $name,
        ]);
    }

    private function teacher(string $first = 'أحمد'): User
    {
        return User::factory()->role(UserRole::Teacher)->create([
            'school_id' => $this->school->id,
            'first_name' => $first,
        ]);
    }

    private function schoolOpen(Weekday $day, string $from, string $to): void
    {
        SchoolDayHours::factory()->create([
            'school_id' => $this->school->id,
            'day_of_week' => $day,
            'starts_at' => $from,
            'ends_at' => $to,
        ]);
    }

    private function free(User $teacher, Weekday $day, string $from, string $to): void
    {
        TeacherAvailability::factory()->create([
            'staff_id' => $teacher->id,
            'day_of_week' => $day,
            'starts_at' => $from,
            'ends_at' => $to,
        ]);
    }

    private function assign(User $teacher, Subject $subject, Section $section, ?int $lessons): TeacherAssignment
    {
        return TeacherAssignment::factory()->create([
            'staff_id' => $teacher->id,
            'subject_id' => $subject->id,
            'section_id' => $section->id,
            'lessons_per_week' => $lessons,
        ]);
    }

    private function generate(): TimetableRun
    {
        $constraints = TimetableConstraints::load($this->school->fresh(), $this->term);
        $analyzer = TimetableAnalyzer::for($constraints);

        return (new TimetableGenerator($constraints, $analyzer))->run($this->school->fresh());
    }

    private function analyze(): TimetableAnalyzer
    {
        return TimetableAnalyzer::for(
            TimetableConstraints::load($this->school->fresh(), $this->term),
        );
    }

    private function conflictCodes(array $conflicts): array
    {
        return array_values(array_unique(array_column($conflicts, 'code')));
    }

    // ------------------------------------------------------------ happy path

    /** ١٣. توليد ناجح: كل الحصص المطلوبة موضوعة، وبلا تعارض. */
    public function test_it_generates_a_complete_valid_timetable(): void
    {
        $section = $this->section();
        $subject = $this->subject();
        $teacher = $this->teacher();

        $this->schoolOpen(Weekday::Saturday, '10:00', '18:00');
        $this->schoolOpen(Weekday::Sunday, '11:00', '18:00');
        $this->free($teacher, Weekday::Saturday, '10:00', '18:00');
        $this->free($teacher, Weekday::Sunday, '11:00', '18:00');
        $this->assign($teacher, $subject, $section, 4);

        $run = $this->generate();

        $this->assertSame(TimetableRunStatus::Generated, $run->status);
        $this->assertSame(4, $run->lessons_placed);
        $this->assertSame(4, $run->lessons_required);
        $this->assertSame([], $run->conflicts);
        $this->assertSame(4, $run->slots()->count());
        $this->assertSame([], TimetableValidator::validateRun($run));
    }

    /** ١٦. العدد المطلوب يُحترَم: أربع حصص أربعٌ لا ثلاث ولا خمس. */
    public function test_it_schedules_exactly_the_required_weekly_lesson_count(): void
    {
        $section = $this->section();
        $teacher = $this->teacher();

        $this->schoolOpen(Weekday::Saturday, '10:00', '18:00');
        $this->free($teacher, Weekday::Saturday, '10:00', '18:00');
        $this->assign($teacher, $this->subject('رياضيات'), $section, 3);
        $this->assign($teacher, $this->subject('فيزياء'), $section, 2);

        $run = $this->generate();

        $this->assertTrue($run->succeeded());
        $this->assertSame(5, $run->slots()->count());

        $bySubject = $run->slots()->get()->groupBy('subject_id')->map->count();
        $this->assertEqualsCanonicalizing([3, 2], $bySubject->values()->all());
    }

    /** الحصّة ستّون دقيقة، لا ثلاثين ولا خمساً وأربعين. */
    public function test_lessons_use_the_configured_duration_not_the_availability_grid(): void
    {
        $section = $this->section();
        $teacher = $this->teacher();

        $this->schoolOpen(Weekday::Saturday, '10:00', '18:00');
        $this->free($teacher, Weekday::Saturday, '10:00', '18:00');
        $this->assign($teacher, $this->subject(), $section, 1);

        $slot = $this->generate()->slots()->first();

        $this->assertSame(
            60,
            TimeRange::minutes($slot->ends_at)
                - TimeRange::minutes($slot->starts_at),
        );
    }

    /** ودوام الشعبة يعلو على افتراض المعهد في طول الحصّة. */
    public function test_section_hours_override_the_school_default_duration(): void
    {
        $section = $this->section();
        $teacher = $this->teacher();

        $this->schoolOpen(Weekday::Saturday, '10:00', '18:00');
        SectionDayHours::factory()->create([
            'section_id' => $section->id,
            'day_of_week' => Weekday::Saturday,
            'starts_at' => '10:00',
            'ends_at' => '18:00',
            'period_minutes' => 90,
        ]);
        $this->free($teacher, Weekday::Saturday, '10:00', '18:00');
        $this->assign($teacher, $this->subject(), $section, 1);

        $slot = $this->generate()->slots()->first();

        $this->assertSame(
            90,
            TimeRange::minutes($slot->ends_at)
                - TimeRange::minutes($slot->starts_at),
        );
    }

    /** ١٢. شبكة نصف الساعة: البدء يجوز على النصف، لا على الحصّة وحدها. */
    public function test_placements_step_on_the_thirty_minute_grid(): void
    {
        $section = $this->section();
        $teacher = $this->teacher();

        // نافذة ٩٠ دقيقة وحصّة ٦٠: ابتداءان ممكنان (١١:٣٠ و١٢:٠٠)، لا ثلاث حصص.
        $this->schoolOpen(Weekday::Saturday, '11:30', '13:00');
        $this->free($teacher, Weekday::Saturday, '11:30', '13:00');
        $this->assign($teacher, $this->subject(), $section, 1);

        $analyzer = $this->analyze();
        $candidates = $analyzer->requirements()[0]->candidates;

        $this->assertCount(2, $candidates);
        $this->assertSame(
            ['11:30–12:30', '12:00–13:00'],
            array_map(fn ($c) => (string) $c['range'], $candidates),
        );
    }

    // ----------------------------------------------------- hard constraints

    /** ١. أستاذ واحد لا يكون في شعبتين في الوقت نفسه. */
    public function test_a_teacher_is_never_double_booked(): void
    {
        $teacher = $this->teacher();
        $sectionA = $this->section('أ');
        $sectionB = $this->section('ب');

        $this->schoolOpen(Weekday::Saturday, '10:00', '18:00');
        $this->free($teacher, Weekday::Saturday, '10:00', '18:00');
        $this->assign($teacher, $this->subject('رياضيات'), $sectionA, 3);
        $this->assign($teacher, $this->subject('فيزياء'), $sectionB, 3);

        $run = $this->generate();
        $this->assertTrue($run->succeeded());

        $byDayTime = [];

        foreach ($run->slots()->get() as $slot) {
            $key = $slot->day_of_week->value.' '.$slot->starts_at;
            $this->assertArrayNotHasKey($key, $byDayTime, "teacher double booked at $key");
            $byDayTime[$key] = true;
        }

        $this->assertSame([], TimetableValidator::validateRun($run));
    }

    /** ٢. شعبة واحدة لا يكون فيها درسان في الوقت نفسه. */
    public function test_a_section_is_never_double_booked(): void
    {
        $section = $this->section();

        $this->schoolOpen(Weekday::Saturday, '10:00', '18:00');

        foreach (['أحمد', 'سارة'] as $name) {
            $teacher = $this->teacher($name);
            $this->free($teacher, Weekday::Saturday, '10:00', '18:00');
            $this->assign($teacher, $this->subject($name === 'أحمد' ? 'رياضيات' : 'عربي'), $section, 3);
        }

        $run = $this->generate();
        $this->assertTrue($run->succeeded());

        $seen = [];

        foreach ($run->slots()->get() as $slot) {
            $key = $slot->day_of_week->value.' '.$slot->starts_at;
            $this->assertArrayNotHasKey($key, $seen, "section double booked at $key");
            $seen[$key] = true;
        }
    }

    /** ٣. لا حصّة خارج تفرّغ الأستاذ. */
    public function test_no_lesson_lands_outside_teacher_availability(): void
    {
        $section = $this->section();
        $teacher = $this->teacher();

        $this->schoolOpen(Weekday::Saturday, '08:00', '18:00');
        // متفرّغ في الطرفين فقط، والوسط (١٠:٠٠–١٢:٠٠) ممنوع.
        $this->free($teacher, Weekday::Saturday, '08:00', '10:00');
        $this->free($teacher, Weekday::Saturday, '12:00', '14:00');
        $this->assign($teacher, $this->subject(), $section, 4);

        $run = $this->generate();
        $this->assertTrue($run->succeeded());

        foreach ($run->slots()->get() as $slot) {
            $start = TimeRange::minutes($slot->starts_at);
            $end = TimeRange::minutes($slot->ends_at);

            $inFirst = $start >= 8 * 60 && $end <= 10 * 60;
            $inSecond = $start >= 12 * 60 && $end <= 14 * 60;

            $this->assertTrue($inFirst || $inSecond, "lesson {$slot->starts_at} is outside availability");
        }
    }

    /** ٤. يوم مغلق للمعهد لا حصّة فيه. */
    public function test_no_lesson_is_placed_on_a_closed_day(): void
    {
        $section = $this->section();
        $teacher = $this->teacher();

        $this->schoolOpen(Weekday::Saturday, '10:00', '18:00');
        // متفرّغ الجمعة أيضاً، لكن المعهد مغلق فيها.
        $this->free($teacher, Weekday::Saturday, '10:00', '18:00');
        $this->free($teacher, Weekday::Friday, '10:00', '18:00');
        $this->assign($teacher, $this->subject(), $section, 3);

        $run = $this->generate();
        $this->assertTrue($run->succeeded());

        $days = $run->slots()->pluck('day_of_week')->map->value->unique()->all();
        $this->assertNotContains(Weekday::Friday->value, $days);
        $this->assertSame([Weekday::Saturday->value], array_values($days));
    }

    /** ٥. لا حصّة قبل بداية الدوام ولا بعد نهايته. */
    public function test_no_lesson_falls_outside_school_working_hours(): void
    {
        $section = $this->section();
        $teacher = $this->teacher();

        $this->schoolOpen(Weekday::Saturday, '12:00', '15:00');
        // متفرّغ يوماً كاملاً؛ دوام المعهد هو ما يقيّده.
        $this->free($teacher, Weekday::Saturday, '08:00', '18:00');
        $this->assign($teacher, $this->subject(), $section, 3);

        $run = $this->generate();
        $this->assertTrue($run->succeeded());

        foreach ($run->slots()->get() as $slot) {
            $this->assertGreaterThanOrEqual(
                12 * 60,
                TimeRange::minutes($slot->starts_at),
            );
            $this->assertLessThanOrEqual(
                15 * 60,
                TimeRange::minutes($slot->ends_at),
            );
        }
    }

    /** ١١. لكل يوم دوامه: الحصص تتبع نافذة يومها لا نافذةً واحدة. */
    public function test_each_day_keeps_its_own_working_hours(): void
    {
        $section = $this->section();
        $teacher = $this->teacher();

        $this->schoolOpen(Weekday::Saturday, '10:00', '12:00');
        $this->schoolOpen(Weekday::Sunday, '16:00', '18:00');
        $this->free($teacher, Weekday::Saturday, '08:00', '18:00');
        $this->free($teacher, Weekday::Sunday, '08:00', '18:00');
        $this->assign($teacher, $this->subject(), $section, 4);

        $run = $this->generate();
        $this->assertTrue($run->succeeded());

        foreach ($run->slots()->get() as $slot) {
            $start = TimeRange::minutes($slot->starts_at);

            if ($slot->day_of_week === Weekday::Saturday) {
                $this->assertGreaterThanOrEqual(10 * 60, $start);
                $this->assertLessThan(12 * 60, $start);
            } else {
                $this->assertGreaterThanOrEqual(16 * 60, $start);
                $this->assertLessThan(18 * 60, $start);
            }
        }
    }

    /** ٦. دوام الشعبة يضيّق دوام المعهد ولا يُلغى. */
    public function test_section_hours_narrow_the_school_window(): void
    {
        $section = $this->section();
        $teacher = $this->teacher();

        $this->schoolOpen(Weekday::Saturday, '08:00', '18:00');
        // شعبة مسائيّة داخل معهدٍ يفتح صباحاً ومساءً.
        SectionDayHours::factory()->create([
            'section_id' => $section->id,
            'day_of_week' => Weekday::Saturday,
            'starts_at' => '15:00',
            'ends_at' => '18:00',
            'period_minutes' => 60,
        ]);
        $this->free($teacher, Weekday::Saturday, '08:00', '18:00');
        $this->assign($teacher, $this->subject(), $section, 2);

        $run = $this->generate();
        $this->assertTrue($run->succeeded());

        foreach ($run->slots()->get() as $slot) {
            $this->assertGreaterThanOrEqual(
                15 * 60,
                TimeRange::minutes($slot->starts_at),
            );
        }
    }

    // ----------------------------------------------------- impossible cases

    /** ٦+١٥. تفرّغ لا يكفي: يُعلَن بالأرقام ولا يُولَّد جدول ناقص. */
    public function test_insufficient_teacher_availability_is_reported_not_silently_trimmed(): void
    {
        $section = $this->section();
        $teacher = $this->teacher();

        $this->schoolOpen(Weekday::Saturday, '10:00', '18:00');
        // ست ساعات تفرّغ، والمطلوب ثماني حصص من ستّين دقيقة.
        $this->free($teacher, Weekday::Saturday, '10:00', '16:00');
        $this->assign($teacher, $this->subject(), $section, 8);

        $run = $this->generate();

        $this->assertSame(TimetableRunStatus::Infeasible, $run->status);
        $this->assertSame(0, $run->slots()->count(), 'no partial timetable may be written');
        $this->assertContains('teacher_capacity_exceeded', $this->conflictCodes($run->conflicts));

        $conflict = collect($run->conflicts)->firstWhere('code', 'teacher_capacity_exceeded');
        $this->assertSame(8, $conflict['context']['required_lessons']);
        $this->assertSame(6, $conflict['context']['available_lessons']);
        $this->assertStringContainsString('أحمد', $conflict['message']);
    }

    /** ٧. سعة الشعبة لا تكفي — تُعلَن بالأرقام. */
    public function test_insufficient_section_capacity_is_reported(): void
    {
        $section = $this->section();

        // ثلاث ساعات دوام = ثلاث حصص، والمطلوب ستّ.
        $this->schoolOpen(Weekday::Saturday, '10:00', '13:00');

        foreach (['أحمد', 'سارة'] as $name) {
            $teacher = $this->teacher($name);
            $this->free($teacher, Weekday::Saturday, '10:00', '13:00');
            $this->assign($teacher, $this->subject($name), $section, 3);
        }

        $run = $this->generate();

        $this->assertSame(TimetableRunStatus::Infeasible, $run->status);
        $this->assertSame(0, $run->slots()->count());

        $conflict = collect($run->conflicts)->firstWhere('code', 'section_capacity_exceeded');
        $this->assertNotNull($conflict);
        $this->assertSame(6, $conflict['context']['required_lessons']);
        $this->assertSame(3, $conflict['context']['available_lessons']);
    }

    /** أستاذ لم يضبط تفرّغه: يُقال باسمه، لا «لا خانات متاحة». */
    public function test_a_teacher_without_availability_is_named(): void
    {
        $section = $this->section();
        $teacher = $this->teacher('سارة');

        $this->schoolOpen(Weekday::Saturday, '10:00', '18:00');
        $this->assign($teacher, $this->subject(), $section, 2);

        $run = $this->generate();

        $this->assertSame(TimetableRunStatus::Infeasible, $run->status);
        $conflict = collect($run->conflicts)->firstWhere('code', 'teacher_has_no_availability');
        $this->assertNotNull($conflict);
        $this->assertStringContainsString('سارة', $conflict['message']);
    }

    /** معهد بلا دوام مضبوط: يُقال ما يجب ضبطه أوّلاً. */
    public function test_a_school_with_no_working_days_is_rejected_early(): void
    {
        $section = $this->section();
        $teacher = $this->teacher();
        $this->free($teacher, Weekday::Saturday, '10:00', '18:00');
        $this->assign($teacher, $this->subject(), $section, 2);

        $run = $this->generate();

        $this->assertSame(TimetableRunStatus::Infeasible, $run->status);
        $this->assertSame(
            ['school_has_no_working_days'],
            $this->conflictCodes($run->conflicts),
        );
    }

    /** إسناد بلا عدد حصص: يُعلَن ولا يسقط صامتاً. */
    public function test_an_assignment_without_a_lesson_count_is_reported(): void
    {
        $section = $this->section();
        $teacher = $this->teacher();
        $subject = Subject::factory()->create([
            'grade_id' => $this->grade->id,
            'term_id' => $this->term->id,
            'periods_per_week' => 0,
        ]);

        $this->schoolOpen(Weekday::Saturday, '10:00', '18:00');
        $this->free($teacher, Weekday::Saturday, '10:00', '18:00');
        $this->assign($teacher, $subject, $section, null);

        $run = $this->generate();

        $this->assertSame(TimetableRunStatus::Infeasible, $run->status);
        $this->assertContains(
            'assignment_has_no_lessons',
            $this->conflictCodes($run->conflicts),
        );
    }

    /** تفرّغ لا يتقاطع مع الدوام أصلاً: لا موضع واحد. */
    public function test_availability_outside_school_hours_yields_no_valid_slot(): void
    {
        $section = $this->section();
        $teacher = $this->teacher();

        $this->schoolOpen(Weekday::Saturday, '14:00', '18:00');
        // متفرّغ صباحاً والمعهد يفتح مساءً.
        $this->free($teacher, Weekday::Saturday, '08:00', '12:00');
        $this->assign($teacher, $this->subject(), $section, 2);

        $run = $this->generate();

        $this->assertSame(TimetableRunStatus::Infeasible, $run->status);
        $this->assertSame(0, $run->slots()->count());
        $this->assertContains(
            'assignment_has_no_valid_slot',
            $this->conflictCodes($run->conflicts),
        );
    }

    /**
     * ٨+٩+١٠. ثلاثة أساتذة وشعبتان وثلاث مواد — ويصحّ الجدول كلّه.
     */
    public function test_it_solves_a_multi_teacher_multi_section_timetable(): void
    {
        $this->schoolOpen(Weekday::Saturday, '10:00', '18:00');
        $this->schoolOpen(Weekday::Sunday, '11:00', '18:00');
        $this->schoolOpen(Weekday::Monday, '12:00', '18:00');

        $sectionA = $this->section('أ');
        $sectionB = $this->section('ب');

        $math = $this->subject('رياضيات');
        $physics = $this->subject('فيزياء');
        $arabic = $this->subject('عربي');

        $ahmad = $this->teacher('أحمد');
        $sara = $this->teacher('سارة');
        $omar = $this->teacher('عمر');

        foreach ([$ahmad, $sara, $omar] as $teacher) {
            $this->free($teacher, Weekday::Saturday, '10:00', '18:00');
            $this->free($teacher, Weekday::Sunday, '11:00', '18:00');
            $this->free($teacher, Weekday::Monday, '12:00', '18:00');
        }

        $this->assign($ahmad, $math, $sectionA, 4);
        $this->assign($ahmad, $math, $sectionB, 3);
        $this->assign($sara, $physics, $sectionA, 3);
        $this->assign($sara, $physics, $sectionB, 3);
        $this->assign($omar, $arabic, $sectionA, 2);
        $this->assign($omar, $arabic, $sectionB, 2);

        $run = $this->generate();

        $this->assertTrue($run->succeeded(), json_encode($run->conflicts, JSON_UNESCAPED_UNICODE));
        $this->assertSame(17, $run->lessons_placed);
        $this->assertSame([], TimetableValidator::validateRun($run));
    }

    /** ١٧. لا حصّة مكرّرة: العدد المطلوب هو الحدّ، والمواضع لا تتكرّر. */
    public function test_it_creates_no_duplicate_entries(): void
    {
        $section = $this->section();
        $teacher = $this->teacher();

        $this->schoolOpen(Weekday::Saturday, '10:00', '18:00');
        $this->schoolOpen(Weekday::Sunday, '10:00', '18:00');
        $this->free($teacher, Weekday::Saturday, '10:00', '18:00');
        $this->free($teacher, Weekday::Sunday, '10:00', '18:00');
        $this->assign($teacher, $this->subject(), $section, 4);

        $slots = $this->generate()->slots()->get();

        $keys = $slots->map(
            fn (ScheduleSlot $s) => $s->day_of_week->value.'|'.$s->starts_at.'|'.$s->section_id,
        );

        $this->assertSame($keys->count(), $keys->unique()->count(), 'duplicate placements written');
        $this->assertSame(4, $slots->count());
    }

    /** الحصص تتوزّع على الأيّام بدل أن تتراكم في يوم واحد. */
    public function test_lessons_spread_across_available_days(): void
    {
        $section = $this->section();
        $teacher = $this->teacher();

        foreach ([Weekday::Saturday, Weekday::Sunday, Weekday::Monday, Weekday::Tuesday] as $day) {
            $this->schoolOpen($day, '10:00', '18:00');
            $this->free($teacher, $day, '10:00', '18:00');
        }

        $this->assign($teacher, $this->subject(), $section, 4);

        $days = $this->generate()->slots()->pluck('day_of_week')->map->value->unique();

        $this->assertCount(4, $days, 'four lessons across four open days should use four days');
    }

    /** حتميّ: نفس المُدخل يعطي نفس الجدول في كل تشغيل. */
    public function test_generation_is_deterministic(): void
    {
        $section = $this->section();
        $teacher = $this->teacher();

        $this->schoolOpen(Weekday::Saturday, '10:00', '16:00');
        $this->schoolOpen(Weekday::Sunday, '10:00', '16:00');
        $this->free($teacher, Weekday::Saturday, '10:00', '16:00');
        $this->free($teacher, Weekday::Sunday, '10:00', '16:00');
        $this->assign($teacher, $this->subject(), $section, 3);

        $first = $this->generate()->slots()->orderBy('day_of_week')->orderBy('starts_at')
            ->get()->map(fn ($s) => $s->day_of_week->value.$s->starts_at)->all();

        $second = $this->generate()->slots()->orderBy('day_of_week')->orderBy('starts_at')
            ->get()->map(fn ($s) => $s->day_of_week->value.$s->starts_at)->all();

        $this->assertSame($first, $second);
    }

    // ------------------------------------------------- regeneration & manual

    /** ١٨+م. إعادة التوليد تحذف المولَّد ولا تمسّ ما بُني يدويّاً. */
    public function test_regenerating_replaces_generated_slots_and_spares_manual_ones(): void
    {
        $section = $this->section();
        $teacher = $this->teacher();
        $subject = $this->subject();

        $this->schoolOpen(Weekday::Saturday, '10:00', '18:00');
        $this->free($teacher, Weekday::Saturday, '10:00', '18:00');
        $this->assign($teacher, $subject, $section, 2);

        // حصّة بناها المستخدم بيده — جدولٌ قائم في الإنتاج.
        $manual = ScheduleSlot::factory()->create([
            'section_id' => $section->id,
            'term_id' => $this->term->id,
            'subject_id' => $subject->id,
            'staff_id' => null,
            'timetable_run_id' => null,
            'day_of_week' => Weekday::Saturday,
            'starts_at' => '17:00',
            'ends_at' => '18:00',
            'period_number' => 99,
        ]);

        $first = $this->generate();
        $this->assertTrue($first->succeeded());

        $second = $this->generate();
        $this->assertTrue($second->succeeded());

        // المولَّد القديم زال، والجديد حلّ مكانه.
        $this->assertSame(0, $first->slots()->count());
        $this->assertSame(2, $second->slots()->count());

        // واليدويّة باقية كما هي. الوقت يُقارَن بالدقائق لا بنصّه: MySQL
        // يعيد «17:00:00» وSQLite «17:00»، والاختبار يعمل على الاثنين.
        $this->assertDatabaseHas('schedule_slots', [
            'id' => $manual->id,
            'timetable_run_id' => null,
        ]);
        $this->assertSame(
            17 * 60,
            TimeRange::minutes($manual->fresh()->starts_at),
        );
    }

    /** والمولّد يجدول **حول** الحصّة اليدويّة: وقتها محجوز. */
    public function test_the_generator_schedules_around_manual_slots(): void
    {
        $section = $this->section();
        $teacher = $this->teacher();
        $subject = $this->subject();

        // نافذة ساعتين، إحداهما محجوزة يدويّاً — فتبقى واحدة.
        $this->schoolOpen(Weekday::Saturday, '10:00', '12:00');
        $this->free($teacher, Weekday::Saturday, '10:00', '12:00');
        $this->assign($teacher, $subject, $section, 1);

        ScheduleSlot::factory()->create([
            'section_id' => $section->id,
            'term_id' => $this->term->id,
            'subject_id' => $subject->id,
            'staff_id' => null,
            'timetable_run_id' => null,
            'day_of_week' => Weekday::Saturday,
            'starts_at' => '10:00',
            'ends_at' => '11:00',
            'period_number' => 1,
        ]);

        $run = $this->generate();

        $this->assertTrue($run->succeeded());
        $this->assertSame(
            11 * 60,
            TimeRange::minutes($run->slots()->first()->starts_at),
        );
    }

    // ------------------------------------------------------ manual placement

    /** ١٨. لا يُحفَظ تعارضٌ صلب ولو كان الفاعل مديراً — أستاذ في مكانين. */
    public function test_manual_placement_rejects_a_teacher_clash(): void
    {
        $teacher = $this->teacher();
        $sectionA = $this->section('أ');
        $sectionB = $this->section('ب');
        $math = $this->subject('رياضيات');
        $physics = $this->subject('فيزياء');

        $this->schoolOpen(Weekday::Saturday, '10:00', '18:00');
        $this->free($teacher, Weekday::Saturday, '10:00', '18:00');
        $this->assign($teacher, $math, $sectionA, 1);
        $this->assign($teacher, $physics, $sectionB, 1);

        ScheduleSlot::factory()->create([
            'section_id' => $sectionA->id,
            'term_id' => $this->term->id,
            'subject_id' => $math->id,
            'staff_id' => $teacher->id,
            'day_of_week' => Weekday::Saturday,
            'starts_at' => '10:00',
            'ends_at' => '11:00',
            'period_number' => 1,
        ]);

        $conflicts = TimetableValidator::validatePlacement(
            sectionId: $sectionB->id,
            termId: $this->term->id,
            subjectId: $physics->id,
            staffId: $teacher->id,
            day: Weekday::Saturday->value,
            startsAt: '10:30',
            endsAt: '11:30',
        );

        $this->assertContains('teacher_double_booked', $this->conflictCodes($conflicts));
    }

    /** وشعبة في درسين. */
    public function test_manual_placement_rejects_a_section_clash(): void
    {
        $section = $this->section();
        $ahmad = $this->teacher('أحمد');
        $sara = $this->teacher('سارة');
        $math = $this->subject('رياضيات');
        $arabic = $this->subject('عربي');

        $this->schoolOpen(Weekday::Saturday, '10:00', '18:00');
        $this->free($ahmad, Weekday::Saturday, '10:00', '18:00');
        $this->free($sara, Weekday::Saturday, '10:00', '18:00');
        $this->assign($ahmad, $math, $section, 1);
        $this->assign($sara, $arabic, $section, 1);

        ScheduleSlot::factory()->create([
            'section_id' => $section->id,
            'term_id' => $this->term->id,
            'subject_id' => $math->id,
            'staff_id' => $ahmad->id,
            'day_of_week' => Weekday::Saturday,
            'starts_at' => '10:00',
            'ends_at' => '11:00',
            'period_number' => 1,
        ]);

        $conflicts = TimetableValidator::validatePlacement(
            sectionId: $section->id,
            termId: $this->term->id,
            subjectId: $arabic->id,
            staffId: $sara->id,
            day: Weekday::Saturday->value,
            startsAt: '10:00',
            endsAt: '11:00',
        );

        $this->assertContains('section_double_booked', $this->conflictCodes($conflicts));
    }

    /** وموضعٌ خارج دوام المعهد. */
    public function test_manual_placement_rejects_a_slot_outside_school_hours(): void
    {
        $section = $this->section();
        $teacher = $this->teacher();
        $subject = $this->subject();

        $this->schoolOpen(Weekday::Saturday, '10:00', '14:00');
        $this->free($teacher, Weekday::Saturday, '08:00', '18:00');
        $this->assign($teacher, $subject, $section, 1);

        $conflicts = TimetableValidator::validatePlacement(
            sectionId: $section->id,
            termId: $this->term->id,
            subjectId: $subject->id,
            staffId: $teacher->id,
            day: Weekday::Saturday->value,
            startsAt: '15:00',
            endsAt: '16:00',
        );

        $this->assertContains('outside_school_hours', $this->conflictCodes($conflicts));
    }

    /** ويومٌ مغلق. */
    public function test_manual_placement_rejects_a_closed_day(): void
    {
        $section = $this->section();
        $teacher = $this->teacher();
        $subject = $this->subject();

        $this->schoolOpen(Weekday::Saturday, '10:00', '14:00');
        $this->free($teacher, Weekday::Friday, '08:00', '18:00');
        $this->assign($teacher, $subject, $section, 1);

        $conflicts = TimetableValidator::validatePlacement(
            sectionId: $section->id,
            termId: $this->term->id,
            subjectId: $subject->id,
            staffId: $teacher->id,
            day: Weekday::Friday->value,
            startsAt: '10:00',
            endsAt: '11:00',
        );

        $this->assertContains('school_closed_that_day', $this->conflictCodes($conflicts));
    }

    /** وأستاذ غير متفرّغ. */
    public function test_manual_placement_rejects_an_unavailable_teacher(): void
    {
        $section = $this->section();
        $teacher = $this->teacher();
        $subject = $this->subject();

        $this->schoolOpen(Weekday::Saturday, '08:00', '18:00');
        $this->free($teacher, Weekday::Saturday, '08:00', '10:00');
        $this->assign($teacher, $subject, $section, 1);

        $conflicts = TimetableValidator::validatePlacement(
            sectionId: $section->id,
            termId: $this->term->id,
            subjectId: $subject->id,
            staffId: $teacher->id,
            day: Weekday::Saturday->value,
            startsAt: '14:00',
            endsAt: '15:00',
        );

        $this->assertContains('teacher_unavailable', $this->conflictCodes($conflicts));
    }

    /** ومادة غير مُسندة إلى هذا الأستاذ. */
    public function test_manual_placement_rejects_an_unassigned_subject(): void
    {
        $section = $this->section();
        $teacher = $this->teacher();
        $subject = $this->subject();
        $other = $this->subject('فرنسي');

        $this->schoolOpen(Weekday::Saturday, '08:00', '18:00');
        $this->free($teacher, Weekday::Saturday, '08:00', '18:00');
        $this->assign($teacher, $subject, $section, 1);

        $conflicts = TimetableValidator::validatePlacement(
            sectionId: $section->id,
            termId: $this->term->id,
            subjectId: $other->id,
            staffId: $teacher->id,
            day: Weekday::Saturday->value,
            startsAt: '10:00',
            endsAt: '11:00',
        );

        $this->assertContains('teacher_not_assigned', $this->conflictCodes($conflicts));
    }

    /** وموضعٌ صالح يمرّ بلا اعتراض. */
    public function test_manual_placement_accepts_a_valid_slot(): void
    {
        $section = $this->section();
        $teacher = $this->teacher();
        $subject = $this->subject();

        $this->schoolOpen(Weekday::Saturday, '10:00', '18:00');
        $this->free($teacher, Weekday::Saturday, '10:00', '18:00');
        $this->assign($teacher, $subject, $section, 1);

        $this->assertSame([], TimetableValidator::validatePlacement(
            sectionId: $section->id,
            termId: $this->term->id,
            subjectId: $subject->id,
            staffId: $teacher->id,
            day: Weekday::Saturday->value,
            startsAt: '11:00',
            endsAt: '12:00',
        ));
    }

    /** وتحريك حصّةٍ لا يتعارض مع موضعها القديم. */
    public function test_moving_a_slot_does_not_clash_with_itself(): void
    {
        $section = $this->section();
        $teacher = $this->teacher();
        $subject = $this->subject();

        $this->schoolOpen(Weekday::Saturday, '10:00', '18:00');
        $this->free($teacher, Weekday::Saturday, '10:00', '18:00');
        $this->assign($teacher, $subject, $section, 1);

        $slot = ScheduleSlot::factory()->create([
            'section_id' => $section->id,
            'term_id' => $this->term->id,
            'subject_id' => $subject->id,
            'staff_id' => $teacher->id,
            'day_of_week' => Weekday::Saturday,
            'starts_at' => '10:00',
            'ends_at' => '11:00',
            'period_number' => 1,
        ]);

        $this->assertSame([], TimetableValidator::validatePlacement(
            sectionId: $section->id,
            termId: $this->term->id,
            subjectId: $subject->id,
            staffId: $teacher->id,
            day: Weekday::Saturday->value,
            startsAt: '10:30',
            endsAt: '11:30',
            ignoreSlotId: $slot->id,
        ));
    }
}
