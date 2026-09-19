<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\ScheduleSlot;
use App\Models\School;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * بناء الجدول بخطوتين: دوام اليوم مرّة، ثم المواد على الحصص.
 */
class SectionScheduleBuilderTest extends TestCase
{
    use RefreshDatabase;

    private Section $section;

    private Term $term;

    /** @var array<int, Subject> */
    private array $subjects = [];

    protected function setUp(): void
    {
        parent::setUp();

        $school = School::factory()->create();
        $year = AcademicYear::factory()->current()->create(['school_id' => $school->id]);
        $grade = Grade::factory()->create(['school_id' => $school->id]);
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

        Sanctum::actingAs(
            User::factory()->role(UserRole::Admin)->create(['school_id' => $school->id]),
        );
    }

    public function test_sunday_to_thursday_are_set_in_one_call(): void
    {
        $this->putJson("/api/sections/{$this->section->id}/hours", [
            'days' => [0, 1, 2, 3, 4],
            'starts_at' => '11:30',
            'ends_at' => '17:00',
            'period_minutes' => 45,
        ])->assertOk();

        $week = collect($this->getJson("/api/sections/{$this->section->id}/hours")->json('data'))
            ->keyBy('day');

        // خمسة أيام بضبطة واحدة، والسبت يبقى عطلة حتى يُضبط وحده.
        foreach ([0, 1, 2, 3, 4] as $day) {
            $this->assertTrue($week[$day]['working'], "اليوم {$day} يجب أن يكون دواماً");
            $this->assertSame('11:30', $week[$day]['starts_at']);
            $this->assertSame(7, $week[$day]['periods_count']);
        }
        $this->assertFalse($week[6]['working']);
    }

    public function test_saturday_keeps_its_own_morning_hours(): void
    {
        $this->putJson("/api/sections/{$this->section->id}/hours", [
            'days' => [0, 1, 2, 3, 4],
            'starts_at' => '11:30',
            'ends_at' => '17:00',
            'period_minutes' => 45,
        ])->assertOk();

        $this->putJson("/api/sections/{$this->section->id}/hours", [
            'days' => [6],
            'starts_at' => '09:00',
            'ends_at' => '13:00',
            'period_minutes' => 45,
        ])->assertOk();

        $week = collect($this->getJson("/api/sections/{$this->section->id}/hours")->json('data'))
            ->keyBy('day');

        $this->assertSame('09:00', $week[6]['starts_at']);
        // ضبط السبت لا يمسّ بقيّة الأيام.
        $this->assertSame('11:30', $week[0]['starts_at']);
    }

    public function test_the_grid_arrives_with_times_already_filled(): void
    {
        $this->putJson("/api/sections/{$this->section->id}/hours", [
            'days' => [0],
            'starts_at' => '11:30',
            'ends_at' => '17:00',
            'period_minutes' => 45,
        ])->assertOk();

        $data = $this->getJson("/api/sections/{$this->section->id}/schedule?day=0")
            ->assertOk()->json('data');

        $this->assertTrue($data['has_hours']);
        $this->assertCount(7, $data['periods']);
        // لا يكتب المستخدم وقتاً: الحصّة تصل برقمها ووقتها.
        $this->assertSame('11:30', $data['periods'][0]['starts_at']);
        $this->assertSame('12:15', $data['periods'][0]['ends_at']);
        $this->assertNull($data['periods'][0]['subject_id']);
    }

    public function test_subjects_are_saved_for_the_whole_day_in_one_call(): void
    {
        $this->putJson("/api/sections/{$this->section->id}/hours", [
            'days' => [0],
            'starts_at' => '11:30',
            'ends_at' => '17:00',
            'period_minutes' => 45,
        ])->assertOk();

        $this->putJson("/api/sections/{$this->section->id}/schedule", [
            'day' => 0,
            'term_id' => $this->term->id,
            'periods' => [
                ['period_number' => 1, 'subject_id' => $this->subjects[0]->id],
                ['period_number' => 2, 'subject_id' => $this->subjects[1]->id],
                ['period_number' => 3, 'subject_id' => null],
            ],
        ])->assertOk();

        $slots = ScheduleSlot::query()->where('day_of_week', 0)->orderBy('period_number')->get();

        $this->assertCount(2, $slots);
        $this->assertSame($this->subjects[0]->id, $slots[0]->subject_id);
        // الوقت جاء من الدوام لا من العميل.
        $this->assertSame('11:30', substr((string) $slots[0]->starts_at, 0, 5));
        $this->assertSame('12:15', substr((string) $slots[1]->starts_at, 0, 5));
    }

    public function test_clearing_a_subject_removes_the_period_rather_than_leaving_it_blank(): void
    {
        $this->putJson("/api/sections/{$this->section->id}/hours", [
            'days' => [0], 'starts_at' => '11:30', 'ends_at' => '17:00', 'period_minutes' => 45,
        ])->assertOk();

        $this->putJson("/api/sections/{$this->section->id}/schedule", [
            'day' => 0,
            'term_id' => $this->term->id,
            'periods' => [['period_number' => 1, 'subject_id' => $this->subjects[0]->id]],
        ])->assertOk();

        $this->putJson("/api/sections/{$this->section->id}/schedule", [
            'day' => 0,
            'term_id' => $this->term->id,
            'periods' => [['period_number' => 1, 'subject_id' => null]],
        ])->assertOk();

        $this->assertSame(0, ScheduleSlot::query()->where('day_of_week', 0)->count());
    }

    public function test_assigning_subjects_before_setting_hours_is_refused(): void
    {
        $this->putJson("/api/sections/{$this->section->id}/schedule", [
            'day' => 0,
            'term_id' => $this->term->id,
            'periods' => [['period_number' => 1, 'subject_id' => $this->subjects[0]->id]],
        ])->assertStatus(422);

        $this->assertSame(0, ScheduleSlot::count());
    }

    public function test_a_day_can_be_turned_back_into_a_holiday(): void
    {
        $this->putJson("/api/sections/{$this->section->id}/hours", [
            'days' => [6], 'starts_at' => '09:00', 'ends_at' => '13:00', 'period_minutes' => 45,
        ])->assertOk();

        $this->deleteJson("/api/sections/{$this->section->id}/hours?day=6")->assertOk();

        $week = collect($this->getJson("/api/sections/{$this->section->id}/hours")->json('data'))
            ->keyBy('day');

        $this->assertFalse($week[6]['working']);
    }

    public function test_the_grid_lists_only_this_grade_subjects(): void
    {
        $otherGrade = Grade::factory()->create(['school_id' => $this->section->grade->school_id]);
        Subject::factory()->create(['grade_id' => $otherGrade->id, 'name' => 'مادة صفّ آخر']);

        $names = collect(
            $this->getJson("/api/sections/{$this->section->id}/schedule-subjects")->json('data')
        )->pluck('name');

        $this->assertCount(3, $names);
        $this->assertNotContains('مادة صفّ آخر', $names);
    }

    public function test_ticked_subjects_land_on_periods_in_the_order_ticked(): void
    {
        $this->putJson("/api/sections/{$this->section->id}/hours", [
            'days' => [0], 'starts_at' => '11:30', 'ends_at' => '17:00', 'period_minutes' => 45,
        ])->assertOk();

        // رياضيات ثم فرنسي ثم كيمياء → الحصص الأولى والثانية والثالثة.
        $this->putJson("/api/sections/{$this->section->id}/schedule/fill", [
            'day' => 0,
            'term_id' => $this->term->id,
            'subject_ids' => [
                $this->subjects[0]->id,
                $this->subjects[1]->id,
                $this->subjects[2]->id,
            ],
        ])->assertOk();

        $slots = ScheduleSlot::query()->where('day_of_week', 0)->orderBy('period_number')->get();

        $this->assertCount(3, $slots);
        $this->assertSame($this->subjects[0]->id, $slots[0]->subject_id);
        $this->assertSame($this->subjects[1]->id, $slots[1]->subject_id);
        $this->assertSame($this->subjects[2]->id, $slots[2]->subject_id);
        // الأوقات تتبع ترتيب الحصص بلا أن يكتبها أحد.
        $this->assertSame('11:30', substr((string) $slots[0]->starts_at, 0, 5));
        $this->assertSame('13:00', substr((string) $slots[2]->starts_at, 0, 5));
    }

    public function test_the_same_subject_may_repeat_across_periods(): void
    {
        $this->putJson("/api/sections/{$this->section->id}/hours", [
            'days' => [0], 'starts_at' => '11:30', 'ends_at' => '17:00', 'period_minutes' => 45,
        ])->assertOk();

        // حصّتا رياضيات متتاليتان أمر عادي في المعاهد.
        $this->putJson("/api/sections/{$this->section->id}/schedule/fill", [
            'day' => 0,
            'term_id' => $this->term->id,
            'subject_ids' => [$this->subjects[0]->id, $this->subjects[0]->id],
        ])->assertOk();

        $slots = ScheduleSlot::query()->where('day_of_week', 0)->orderBy('period_number')->get();

        $this->assertCount(2, $slots);
        $this->assertSame($this->subjects[0]->id, $slots[1]->subject_id);
    }

    public function test_refilling_a_day_replaces_it_rather_than_appending(): void
    {
        $this->putJson("/api/sections/{$this->section->id}/hours", [
            'days' => [0], 'starts_at' => '11:30', 'ends_at' => '17:00', 'period_minutes' => 45,
        ])->assertOk();

        $this->putJson("/api/sections/{$this->section->id}/schedule/fill", [
            'day' => 0, 'term_id' => $this->term->id,
            'subject_ids' => [$this->subjects[0]->id, $this->subjects[1]->id, $this->subjects[2]->id],
        ])->assertOk();

        $this->putJson("/api/sections/{$this->section->id}/schedule/fill", [
            'day' => 0, 'term_id' => $this->term->id,
            'subject_ids' => [$this->subjects[2]->id],
        ])->assertOk();

        $slots = ScheduleSlot::query()->where('day_of_week', 0)->get();

        // القائمة المرسَلة هي اليوم كلّه؛ ما لم يُذكَر يُفرَّغ.
        $this->assertCount(1, $slots);
        $this->assertSame($this->subjects[2]->id, $slots[0]->subject_id);
    }

    public function test_more_subjects_than_periods_are_ignored_not_crammed(): void
    {
        $this->putJson("/api/sections/{$this->section->id}/hours", [
            'days' => [6], 'starts_at' => '09:00', 'ends_at' => '10:30', 'period_minutes' => 45,
        ])->assertOk();

        // يومان حصّتان فقط، وثلاث مواد مؤشَّرة.
        $this->putJson("/api/sections/{$this->section->id}/schedule/fill", [
            'day' => 6, 'term_id' => $this->term->id,
            'subject_ids' => [
                $this->subjects[0]->id, $this->subjects[1]->id, $this->subjects[2]->id,
            ],
        ])->assertOk();

        $this->assertSame(2, ScheduleSlot::query()->where('day_of_week', 6)->count());
    }
}
