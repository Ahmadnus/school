<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\Weekday;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\School;
use App\Models\SchoolDayHours;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\TeacherAvailability;
use App\Models\Term;
use App\Models\TimetableRun;
use App\Models\User;
use App\Services\Timetable\TimetableAnalyzer;
use App\Services\Timetable\TimetableConstraints;
use App\Services\Timetable\TimetableGenerator;
use Database\Seeders\TeachingStaffSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * البذر يجب أن يُخرج بيانات **يستطيع المولّد أن يعمل عليها**، لا مجرّد صفوف
 * في جداول: دوامٌ لكل يوم، وإسنادٌ لكل شعبة، وأوقات فراغ تكفي.
 */
class TeachingStaffSeederTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Term $term;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $year = AcademicYear::factory()->current()->create(['school_id' => $this->school->id]);
        $this->term = Term::factory()->create(['academic_year_id' => $year->id]);

        foreach (['التاسع', 'العلمي'] as $gradeName) {
            $grade = Grade::factory()->create([
                'school_id' => $this->school->id,
                'name' => $gradeName,
            ]);

            foreach (['أ', 'ب'] as $sectionName) {
                Section::factory()->create([
                    'grade_id' => $grade->id,
                    'academic_year_id' => $year->id,
                    'name' => $sectionName,
                ]);
            }

            // الاسم نفسه في صفّين: يجب أن يأخذه أستاذ واحد.
            foreach (['لغة عربية — '.$gradeName, 'رياضيات — '.$gradeName] as $subjectName) {
                Subject::factory()->create([
                    'grade_id' => $grade->id,
                    'name' => $subjectName,
                    'term_id' => $this->term->id,
                    'periods_per_week' => 3,
                ]);
            }
        }
    }

    private function seedStaff(): void
    {
        $this->seed(TeachingStaffSeeder::class);
    }

    public function test_school_hours_are_eleven_to_six_and_saturday_shorter(): void
    {
        $this->seedStaff();

        $hours = SchoolDayHours::query()
            ->where('school_id', $this->school->id)
            ->get()
            ->keyBy(fn (SchoolDayHours $row) => $row->day_of_week->value);

        // كل أيّام الأسبوع لها دوام — سبعة، لا خمسة.
        $this->assertCount(7, $hours);

        $sunday = $hours[Weekday::Sunday->value];
        $this->assertSame('11:00', substr((string) $sunday->starts_at, 0, 5));
        $this->assertSame('18:00', substr((string) $sunday->ends_at, 0, 5));

        $saturday = $hours[Weekday::Saturday->value];
        $this->assertSame('10:00', substr((string) $saturday->starts_at, 0, 5));
        $this->assertSame('16:00', substr((string) $saturday->ends_at, 0, 5));
    }

    public function test_the_same_subject_in_two_grades_gets_one_teacher(): void
    {
        $this->seedStaff();

        // «لغة عربية» في التاسع والعلمي: أستاذٌ واحد. أستاذان يُفقدان المولّد
        // قيداً حقيقياً — أنّ الأستاذ لا يكون في شعبتين في الوقت نفسه.
        $teachers = TeacherAssignment::query()
            ->whereHas('subject', fn ($q) => $q->where('name', 'like', 'لغة عربية%'))
            ->pluck('staff_id')
            ->unique();

        $this->assertCount(1, $teachers);
    }

    public function test_every_section_of_every_subject_is_assigned(): void
    {
        $this->seedStaff();

        $subjects = Subject::query()->count();
        $sectionsPerGrade = 2;

        $this->assertSame(
            $subjects * $sectionsPerGrade,
            TeacherAssignment::query()->count(),
        );

        // null تعني «خُذ ما على المادّة»: عدد الحصص مصدرُه واحد لا اثنان.
        $this->assertSame(
            0,
            TeacherAssignment::query()->whereNotNull('lessons_per_week')->count(),
        );
    }

    public function test_availability_stays_inside_school_hours(): void
    {
        $this->seedStaff();

        $rows = TeacherAvailability::query()->get();
        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $saturday = $row->day_of_week === Weekday::Saturday;
            $open = $saturday ? '10:00' : '11:00';
            $close = $saturday ? '16:00' : '18:00';

            $from = substr((string) $row->starts_at, 0, 5);
            $to = substr((string) $row->ends_at, 0, 5);

            // فراغٌ قبل الافتتاح أو بعد الإغلاق وقتٌ لا وجود له.
            $this->assertGreaterThanOrEqual($open, $from, "بداية {$from} قبل {$open}");
            $this->assertLessThanOrEqual($close, $to, "نهاية {$to} بعد {$close}");
            // ولا نافذة أقصر من ثلاث ساعات: تجعل العجز بيانات لا قيداً.
            $this->assertGreaterThanOrEqual(180, $this->minutes($to) - $this->minutes($from));
        }
    }

    public function test_rerunning_does_not_duplicate_anything(): void
    {
        $this->seedStaff();

        $teachers = User::query()->where('role', UserRole::Teacher)->count();
        $assignments = TeacherAssignment::query()->count();
        $windows = TeacherAvailability::query()->count();
        $hours = SchoolDayHours::query()->count();

        $this->seedStaff();

        $this->assertSame($teachers, User::query()->where('role', UserRole::Teacher)->count());
        $this->assertSame($assignments, TeacherAssignment::query()->count());
        $this->assertSame($windows, TeacherAvailability::query()->count());
        $this->assertSame($hours, SchoolDayHours::query()->count());
    }

    public function test_the_generator_builds_a_timetable_from_this_seed(): void
    {
        $this->seedStaff();

        // هذا هو معنى البذر: ليس صفوفاً في جداول، بل مُدخلٌ يُخرج جدولاً.
        $constraints = TimetableConstraints::load($this->school->fresh(), $this->term);
        $run = (new TimetableGenerator($constraints, TimetableAnalyzer::for($constraints)))
            ->run($this->school->fresh());

        $this->assertTrue(
            $run->succeeded(),
            'فشل التوليد: '.json_encode($run->conflicts, JSON_UNESCAPED_UNICODE),
        );

        // أربع موادّ × شعبتين × ٣ حصص = ٢٤ حصّة.
        $this->assertSame(24, $run->lessons_placed);
        $this->assertSame(24, TimetableRun::query()->find($run->id)->slots()->count());
    }

    public function test_it_holds_at_the_real_school_size(): void
    {
        // مقاس المدرسة على الخادم اليوم: أربعة صفوف، سبع شعب، ٤١ مادّة —
        // نحو ٢١٩ حصّة أسبوعيّة. البذر الذي ينجح على شعبتين ويعجز هنا بذرٌ
        // لا ينفع: الاختبار يُقاس على الحجم الحقيقي لا على حجمٍ مريح.
        $year = AcademicYear::query()->where('school_id', $this->school->id)->first();

        foreach ([['أدبي', 2, 11], ['حادي عشر', 1, 9], ['الثامن', 2, 8]] as [$name, $sections, $subjects]) {
            $grade = Grade::factory()->create(['school_id' => $this->school->id, 'name' => $name]);

            for ($s = 0; $s < $sections; $s++) {
                Section::factory()->create([
                    'grade_id' => $grade->id,
                    'academic_year_id' => $year->id,
                    'name' => chr(97 + $s),
                ]);
            }

            $pool = ['لغة عربية', 'رياضيات', 'لغة إنجليزية', 'لغة فرنسية', 'ديانة',
                'تاريخ', 'جغرافيا', 'فلسفة', 'فيزياء', 'كيمياء', 'علوم'];

            for ($i = 0; $i < $subjects; $i++) {
                Subject::factory()->create([
                    'grade_id' => $grade->id,
                    'name' => $pool[$i].' — '.$name,
                    'term_id' => $this->term->id,
                    'periods_per_week' => 3,
                ]);
            }
        }

        $this->seedStaff();

        $required = Subject::query()->get()->sum(
            fn (Subject $s) => Section::query()->where('grade_id', $s->grade_id)->count() * 3,
        );

        $constraints = TimetableConstraints::load($this->school->fresh(), $this->term);
        $run = (new TimetableGenerator($constraints, TimetableAnalyzer::for($constraints)))
            ->run($this->school->fresh());

        $this->assertTrue(
            $run->succeeded(),
            "فشل التوليد على {$required} حصّة: ".json_encode($run->conflicts, JSON_UNESCAPED_UNICODE),
        );
        $this->assertSame($required, $run->lessons_placed);
    }

    private function minutes(string $clock): int
    {
        [$h, $m] = array_map('intval', explode(':', $clock));

        return $h * 60 + $m;
    }
}
