<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\ScheduleSlot;
use App\Models\School;
use App\Models\SchoolDayHours;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * الأسبوع كلّه من صفحة واحدة: دوامٌ لكل يوم، ثمّ مواد على حصص مشتقّة.
 */
class SectionScheduleWeekTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Section $section;

    private Term $term;

    /** @var array<int, Subject> */
    private array $subjects = [];

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $year = AcademicYear::factory()->current()->create(['school_id' => $this->school->id]);
        $grade = Grade::factory()->create(['school_id' => $this->school->id]);
        $this->section = Section::factory()->create([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
        ]);
        $this->term = Term::factory()->create(['academic_year_id' => $year->id]);

        foreach (['رياضيات', 'فيزياء', 'كيمياء'] as $name) {
            $this->subjects[] = Subject::factory()->create([
                'grade_id' => $grade->id,
                'name' => $name,
            ]);
        }

        $this->teacher = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);

        Sanctum::actingAs(
            User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]),
        );
    }

    /** @param  array<int, array<string, mixed>>  $days */
    private function save(array $days): TestResponse
    {
        return $this->putJson("/api/sections/{$this->section->id}/schedule/week", [
            'term_id' => $this->term->id,
            'days' => $days,
        ]);
    }

    /** @param  array<int, int|null>  $subjectIds */
    private function day(int $day, array $subjectIds, string $startsAt = '08:00'): array
    {
        return [
            'day' => $day,
            'working' => true,
            'starts_at' => $startsAt,
            'ends_at' => '12:00',
            'period_minutes' => 45,
            'break_minutes' => 0,
            'periods' => array_values(array_map(fn (int $index, ?int $id) => [
                'period_number' => $index + 1,
                'subject_id' => $id,
            ], array_keys($subjectIds), $subjectIds)),
        ];
    }

    public function test_the_week_arrives_with_periods_already_timed(): void
    {
        $this->save([$this->day(0, [$this->subjects[0]->id, $this->subjects[1]->id])])->assertOk();

        $body = $this->getJson(
            "/api/sections/{$this->section->id}/schedule/week?term_id={$this->term->id}",
        )->assertOk()->json('data');

        $sunday = collect($body['days'])->firstWhere('day', 0);

        $this->assertTrue($sunday['working']);
        $this->assertSame('08:00', $sunday['starts_at']);
        $this->assertSame('08:00', $sunday['periods'][0]['starts_at']);
        $this->assertSame('08:45', $sunday['periods'][0]['ends_at']);
        $this->assertSame($this->subjects[0]->id, $sunday['periods'][0]['subject_id']);
        $this->assertSame($this->subjects[1]->id, $sunday['periods'][1]['subject_id']);
        // الحصص الباقية تصل فارغة بأوقاتها — لا تُخفى ولا تُخترع.
        $this->assertNull($sunday['periods'][2]['subject_id']);
    }

    public function test_the_whole_week_is_saved_in_one_call(): void
    {
        $this->save([
            $this->day(0, [$this->subjects[0]->id, $this->subjects[1]->id]),
            $this->day(1, [$this->subjects[1]->id]),
            $this->day(2, [$this->subjects[2]->id], '08:15'),
        ])->assertOk();

        $this->assertSame(4, ScheduleSlot::where('section_id', $this->section->id)->count());
        $this->assertSame('08:15', substr(
            (string) ScheduleSlot::where('day_of_week', 2)->first()->starts_at,
            0,
            5,
        ));
    }

    public function test_each_day_keeps_its_own_start_time(): void
    {
        $this->save([
            $this->day(1, [$this->subjects[0]->id]),
            $this->day(2, [$this->subjects[0]->id], '08:15'),
        ])->assertOk();

        $days = collect(
            $this->getJson("/api/sections/{$this->section->id}/schedule/week")->json('data.days'),
        )->keyBy('day');

        $this->assertSame('08:00', $days[1]['starts_at']);
        $this->assertSame('08:15', $days[2]['starts_at']);
    }

    public function test_the_assigned_teacher_is_filled_in_without_being_asked_for(): void
    {
        TeacherAssignment::create([
            'staff_id' => $this->teacher->id,
            'subject_id' => $this->subjects[0]->id,
            'section_id' => $this->section->id,
        ]);

        $this->save([$this->day(0, [$this->subjects[0]->id])])->assertOk();

        $this->assertSame(
            $this->teacher->id,
            ScheduleSlot::where('section_id', $this->section->id)->first()->staff_id,
        );
    }

    public function test_a_subject_with_two_teachers_is_left_for_the_user_to_choose(): void
    {
        $other = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);

        foreach ([$this->teacher, $other] as $staff) {
            TeacherAssignment::create([
                'staff_id' => $staff->id,
                'subject_id' => $this->subjects[0]->id,
                'section_id' => $this->section->id,
            ]);
        }

        $options = collect(
            $this->getJson("/api/sections/{$this->section->id}/schedule/week")->json('data.subjects'),
        )->firstWhere('id', $this->subjects[0]->id);

        $this->assertNull($options['default_teacher_id']);
        $this->assertCount(2, $options['teachers']);
    }

    public function test_a_teacher_outside_the_subject_assignment_is_refused(): void
    {
        TeacherAssignment::create([
            'staff_id' => $this->teacher->id,
            'subject_id' => $this->subjects[0]->id,
            'section_id' => $this->section->id,
        ]);

        $stranger = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);

        $day = $this->day(0, [$this->subjects[0]->id]);
        $day['periods'][0]['teacher_id'] = $stranger->id;

        $this->save([$day])->assertStatus(422)->assertJsonValidationErrors('days.0.periods.0.teacher_id');
    }

    public function test_a_teacher_cannot_be_in_two_sections_at_once(): void
    {
        $sibling = Section::factory()->create([
            'grade_id' => $this->section->grade_id,
            'academic_year_id' => $this->section->academic_year_id,
        ]);

        ScheduleSlot::create([
            'section_id' => $sibling->id,
            'term_id' => $this->term->id,
            'subject_id' => $this->subjects[0]->id,
            'staff_id' => $this->teacher->id,
            'day_of_week' => 0,
            'starts_at' => '08:00',
            'ends_at' => '08:45',
            'period_number' => 1,
        ]);

        $day = $this->day(0, [$this->subjects[0]->id]);
        $day['periods'][0]['teacher_id'] = $this->teacher->id;

        $this->save([$day])->assertStatus(422)->assertJsonValidationErrors('days.0.periods.0.teacher_id');
    }

    public function test_a_period_beyond_the_day_capacity_is_refused(): void
    {
        $this->save([[
            'day' => 0,
            'working' => true,
            'starts_at' => '08:00',
            'ends_at' => '09:30',
            'period_minutes' => 45,
            'periods' => [
                ['period_number' => 1, 'subject_id' => $this->subjects[0]->id],
                ['period_number' => 9, 'subject_id' => $this->subjects[1]->id],
            ],
        ]])->assertStatus(422)->assertJsonValidationErrors('days.0.periods.1.period_number');
    }

    public function test_a_duplicate_period_number_is_refused(): void
    {
        $this->save([[
            'day' => 0,
            'working' => true,
            'starts_at' => '08:00',
            'ends_at' => '12:00',
            'period_minutes' => 45,
            'periods' => [
                ['period_number' => 1, 'subject_id' => $this->subjects[0]->id],
                ['period_number' => 1, 'subject_id' => $this->subjects[1]->id],
            ],
        ]])->assertStatus(422)->assertJsonValidationErrors('days.0.periods.1.period_number');
    }

    public function test_a_day_that_ends_before_it_starts_is_refused(): void
    {
        $day = $this->day(0, [$this->subjects[0]->id]);
        $day['ends_at'] = '07:00';

        $this->save([$day])->assertStatus(422)->assertJsonValidationErrors('days.0.ends_at');
    }

    public function test_a_subject_from_another_grade_is_refused(): void
    {
        $foreign = Subject::factory()->create([
            'grade_id' => Grade::factory()->create(['school_id' => $this->school->id])->id,
        ]);

        $this->save([$this->day(0, [$foreign->id])])
            ->assertStatus(422)
            ->assertJsonValidationErrors('days.0.periods.0.subject_id');
    }

    public function test_nothing_is_written_when_any_day_is_invalid(): void
    {
        $good = $this->day(0, [$this->subjects[0]->id]);
        $bad = $this->day(1, [$this->subjects[0]->id]);
        $bad['ends_at'] = '07:00';

        $this->save([$good, $bad])->assertStatus(422);

        $this->assertSame(0, ScheduleSlot::where('section_id', $this->section->id)->count());
    }

    public function test_turning_a_day_off_clears_its_hours_and_its_periods(): void
    {
        $this->save([$this->day(0, [$this->subjects[0]->id])])->assertOk();
        $this->assertSame(1, ScheduleSlot::where('section_id', $this->section->id)->count());

        $this->save([['day' => 0, 'working' => false]])->assertOk();

        $this->assertSame(0, ScheduleSlot::where('section_id', $this->section->id)->count());
        $this->assertFalse(
            collect($this->getJson("/api/sections/{$this->section->id}/schedule/week")->json('data.days'))
                ->firstWhere('day', 0)['working'],
        );
    }

    public function test_clearing_one_subject_removes_only_that_period(): void
    {
        $this->save([$this->day(0, [$this->subjects[0]->id, $this->subjects[1]->id])])->assertOk();

        $this->save([$this->day(0, [$this->subjects[0]->id, null])])->assertOk();

        $this->assertSame(1, ScheduleSlot::where('section_id', $this->section->id)->count());
    }

    public function test_the_school_day_hours_are_offered_as_defaults(): void
    {
        SchoolDayHours::create([
            'school_id' => $this->school->id,
            'day_of_week' => 1,
            'starts_at' => '08:00',
            'ends_at' => '14:00',
        ]);

        $defaults = $this->getJson("/api/sections/{$this->section->id}/schedule/week")
            ->assertOk()
            ->json('data.defaults');

        $monday = collect($defaults['days'])->firstWhere('day', 1);
        $friday = collect($defaults['days'])->firstWhere('day', 5);

        $this->assertTrue($monday['school_working']);
        $this->assertSame('08:00', $monday['starts_at']);
        // الجمعة ليست من أيام الأسبوع الدراسي، فلا تُعرَض أصلاً.
        $this->assertNull($friday);
    }

    public function test_saving_returns_the_reloaded_week(): void
    {
        $days = $this->save([$this->day(0, [$this->subjects[0]->id])])
            ->assertOk()
            ->json('data.days');

        $this->assertSame(
            $this->subjects[0]->id,
            collect($days)->firstWhere('day', 0)['periods'][0]['subject_id'],
        );
    }
}
