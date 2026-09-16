<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\AttendanceRecord;
use App\Models\Grade;
use App\Models\ScheduleSlot;
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

class InsightsTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private AcademicYear $year;

    private Term $term;

    private Section $section;

    private Section $otherSection;

    private User $admin;

    private User $teacher;

    private User $guardian;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create(['absence_warning_threshold' => 2]);
        $this->year = AcademicYear::factory()->current()->create(['school_id' => $this->school->id]);
        $this->term = Term::factory()->create(['academic_year_id' => $this->year->id, 'is_current' => true]);
        $grade = Grade::factory()->create(['school_id' => $this->school->id]);
        $this->section = Section::factory()->create(['grade_id' => $grade->id, 'academic_year_id' => $this->year->id]);
        $this->otherSection = Section::factory()->create(['grade_id' => $grade->id, 'academic_year_id' => $this->year->id]);

        $this->admin = User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]);
        $this->teacher = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);
        $this->guardian = User::factory()->role(UserRole::Guardian)->create(['school_id' => $this->school->id]);

        $subject = Subject::factory()->create(['grade_id' => $grade->id, 'term_id' => $this->term->id]);
        TeacherAssignment::factory()->create([
            'staff_id' => $this->teacher->id,
            'subject_id' => $subject->id,
            'section_id' => $this->section->id,
        ]);
    }

    public function test_guardian_and_driver_are_refused(): void
    {
        Sanctum::actingAs($this->guardian);
        $this->getJson('/api/insights/attention')->assertForbidden();
        $this->getJson('/api/insights/data-health')->assertForbidden();

        Sanctum::actingAs(User::factory()->role(UserRole::Driver)->create(['school_id' => $this->school->id]));
        $this->getJson('/api/insights/attention')->assertForbidden();
    }

    public function test_teacher_gets_scoped_attention_but_not_admin_insights(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->getJson('/api/insights/attention')->assertOk()->assertJsonStructure(['data' => ['generated_at', 'items']]);
        $this->getJson('/api/insights/schedule-conflicts')->assertOk();
        $this->getJson('/api/insights/staff-workload')->assertForbidden();
        $this->getJson('/api/insights/data-health')->assertForbidden();
        $this->getJson('/api/insights/setup-progress')->assertForbidden();
    }

    public function test_absence_threshold_item_counts_students_over_the_limit(): void
    {
        $student = Student::factory()->create(['school_id' => $this->school->id]);
        StudentEnrollment::factory()->create([
            'student_id' => $student->id, 'section_id' => $this->section->id, 'academic_year_id' => $this->year->id,
        ]);
        foreach (['2026-09-01', '2026-09-02'] as $date) {
            AttendanceRecord::factory()->create([
                'student_id' => $student->id, 'section_id' => $this->section->id,
                'date' => $date, 'status' => AttendanceStatus::Absent,
            ]);
        }

        Sanctum::actingAs($this->admin);
        $items = collect($this->getJson('/api/insights/attention')->assertOk()->json('data.items'))->keyBy('key');

        $this->assertSame(1, $items['absence_threshold']['count']);
        $this->assertSame('students', $items['absence_threshold']['route']);
        $this->assertSame('danger', $items['absence_threshold']['severity']);
    }

    public function test_schedule_conflicts_detect_double_booked_teacher(): void
    {
        $subject = Subject::query()->first();
        foreach ([$this->section, $this->otherSection] as $i => $section) {
            ScheduleSlot::factory()->create([
                'section_id' => $section->id, 'term_id' => $this->term->id, 'subject_id' => $subject->id,
                'staff_id' => $this->teacher->id, 'day_of_week' => 1,
                'starts_at' => '08:00', 'ends_at' => '08:45', 'period_number' => $i + 1, 'room' => null,
            ]);
        }

        Sanctum::actingAs($this->admin);
        $conflicts = $this->getJson('/api/insights/schedule-conflicts')->assertOk()->json('data');
        $this->assertCount(1, $conflicts);
        $this->assertSame('teacher', $conflicts[0]['type']);
        $this->assertCount(2, $conflicts[0]['slots']);

        $items = collect($this->getJson('/api/insights/attention')->json('data.items'))->keyBy('key');
        $this->assertSame(1, $items['schedule_conflicts']['count']);

        // The teacher involved sees it too.
        Sanctum::actingAs($this->teacher);
        $this->assertCount(1, $this->getJson('/api/insights/schedule-conflicts')->json('data'));
    }

    public function test_setup_progress_percent_matches_done_steps(): void
    {
        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/insights/setup-progress')->assertOk()->json('data');

        $done = collect($data['steps'])->where('done', true)->count();
        $this->assertSame((int) round($done / count($data['steps']) * 100), $data['percent']);
        $this->assertTrue(collect($data['steps'])->firstWhere('key', 'academic_year')['done']);
        $this->assertFalse(collect($data['steps'])->firstWhere('key', 'fee_types')['done']);
    }

    public function test_class_health_and_data_health_respond_with_explainable_rows(): void
    {
        Sanctum::actingAs($this->admin);

        $rows = $this->getJson('/api/insights/class-health')->assertOk()->json('data');
        $this->assertCount(2, $rows);
        $this->assertSame('healthy', $rows[0]['status']);
        $this->assertIsArray($rows[0]['reasons']);

        $issues = collect($this->getJson('/api/insights/data-health')->assertOk()->json('data.issues'))->keyBy('key');
        // The second section has no teacher; both students-related counts are zero (no students).
        $this->assertSame(1, $issues['sections_without_teacher']['count']);
        $this->assertArrayNotHasKey('students_without_guardian', $issues->all());

        $workload = $this->getJson('/api/insights/staff-workload')->assertOk()->json('data');
        $teacherRow = collect($workload)->firstWhere('user_id', $this->teacher->id);
        $this->assertSame(1, $teacherRow['sections_count']);
    }
}
