<?php

namespace Database\Seeders;

use App\Enums\EnrollmentScope;
use App\Enums\GuardianRelation;
use App\Enums\NotificationApp;
use App\Enums\UserRole;
use App\Enums\Weekday;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\Guardian;
use App\Models\Holiday;
use App\Models\Rubric;
use App\Models\ScheduleSlot;
use App\Models\PostType;
use App\Models\School;
use App\Models\SchoolNotificationSetting;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $school = School::firstOrCreate(
            ['name' => 'المعهد السوري'],
            ['email' => 'info@example.test', 'currency' => 'SYP'],
        );

        User::firstOrCreate(
            ['email' => 'admin@example.test'],
            [
                'school_id' => $school->id,
                'first_name' => 'مشرف',
                'last_name' => 'عام',
                'phone' => '0900000000',
                'password' => 'password',
                'role' => UserRole::SuperAdmin,
            ],
        );

        $teacher = User::firstOrCreate(
            ['school_id' => $school->id, 'phone' => '0911111111'],
            [
                'first_name' => 'أحمد',
                'last_name' => 'عمر',
                'specialty' => 'لغة عربية',
                'role' => UserRole::Teacher,
            ],
        );

        $year = AcademicYear::firstOrCreate(
            ['school_id' => $school->id, 'name' => '2026-2027'],
            ['start_date' => '2026-09-01', 'end_date' => '2027-06-15'],
        );
        $year->markAsCurrent();

        $firstTerm = $year->terms()->firstOrCreate(
            ['name' => 'الفصل الأول'],
            ['start_date' => '2026-09-01', 'end_date' => '2027-01-15', 'is_current' => true],
        );
        $year->terms()->firstOrCreate(
            ['name' => 'الفصل الثاني'],
            ['start_date' => '2027-01-20', 'end_date' => '2027-06-15'],
        );

        foreach ([['واجب', false], ['مذاكرة', false], ['امتحان نهائي', true]] as $order => [$typeName, $isExam]) {
            AssessmentType::firstOrCreate(
                ['school_id' => $school->id, 'name' => $typeName],
                ['sort_order' => $order + 1, 'is_exam' => $isExam, 'is_default' => $order === 0],
            );
        }

        $rubric = Rubric::firstOrCreate(
            ['school_id' => $school->id, 'name' => 'المقياس الوصفي الافتراضي'],
            ['is_default' => true],
        );

        foreach ([['ممتاز', 4], ['جيد جداً', 3], ['جيد', 2], ['مقبول', 1]] as $order => [$levelName, $value]) {
            $rubric->levels()->firstOrCreate(
                ['name' => $levelName],
                ['value' => $value, 'sort_order' => $order + 1],
            );
        }

        $homework = AssessmentType::where('school_id', $school->id)->where('name', 'واجب')->first();

        // One teacher cannot be in two rooms at once, so each section gets its
        // own period number on the shared Sunday slot.
        $period = 0;

        foreach (['علمي', 'أدبي'] as $order => $gradeName) {
            $grade = Grade::firstOrCreate(
                ['school_id' => $school->id, 'name' => $gradeName],
                ['sort_order' => $order + 1],
            );

            $subject = Subject::firstOrCreate(
                ['grade_id' => $grade->id, 'term_id' => $firstTerm->id, 'name' => 'لغة عربية'],
                ['max_score' => 100, 'pass_score' => 50, 'periods_per_week' => 5],
            );

            Assessment::firstOrCreate(
                ['subject_id' => $subject->id, 'name' => 'واجب الوحدة الأولى'],
                [
                    'assessment_type_id' => $homework->id,
                    'max_score' => 20,
                    'weight_percent' => 10,
                ],
            );

            foreach (['إناث', 'ذكور'] as $index => $sectionName) {
                $section = Section::firstOrCreate(
                    [
                        'grade_id' => $grade->id,
                        'academic_year_id' => $year->id,
                        'name' => $sectionName,
                    ],
                    ['capacity' => 30, 'sort_order' => $index + 1],
                );

                TeacherAssignment::firstOrCreate([
                    'staff_id' => $teacher->id,
                    'subject_id' => $subject->id,
                    'section_id' => $section->id,
                ]);

                ScheduleSlot::firstOrCreate(
                    [
                        'section_id' => $section->id,
                        'term_id' => $firstTerm->id,
                        'day_of_week' => Weekday::Sunday,
                        'period_number' => ++$period,
                    ],
                    [
                        'subject_id' => $subject->id,
                        'staff_id' => $teacher->id,
                        'starts_at' => sprintf('%02d:00', 7 + $period),
                        'ends_at' => sprintf('%02d:45', 7 + $period),
                        'room' => 'A'.$period,
                    ],
                );

                Student::factory()
                    ->count(3)
                    ->create(['school_id' => $school->id])
                    ->each(fn (Student $student) => $student->enrollments()->create([
                        'section_id' => $section->id,
                        'academic_year_id' => $year->id,
                        'scope' => EnrollmentScope::FullYear,
                        'enrolled_at' => $year->start_date,
                    ]));
            }
        }

        // The 13 fixed post types. Only is_enabled and min_role are editable
        // later; "warning" is the one that needs approval by default.
        foreach (PostType::CATALOG as $index => $entry) {
            PostType::firstOrCreate(
                ['school_id' => $school->id, 'key' => $entry['key']],
                [
                    'group' => $entry['group'],
                    'sort_order' => $index + 1,
                    'min_role' => $entry['key'] === 'warning' ? UserRole::Admin : UserRole::Teacher,
                    'requires_approval' => $entry['key'] === 'warning',
                ],
            );
        }

        // Both notification levels start enabled; delivery needs both (8-a).
        foreach (NotificationApp::cases() as $app) {
            foreach (SchoolNotificationSetting::CATALOG as $entry) {
                SchoolNotificationSetting::firstOrCreate(
                    ['school_id' => $school->id, 'app' => $app, 'key' => $entry['key']],
                    ['group' => $entry['group']],
                );
            }
        }

        Holiday::firstOrCreate(
            ['school_id' => $school->id, 'name' => 'عطلة نصف العام'],
            [
                'academic_year_id' => $year->id,
                'start_date' => '2027-01-16',
                'end_date' => '2027-01-19',
            ],
        );

        // A default instalment type with a two-payment schedule.
        $feeType = FeeType::firstOrCreate(
            ['school_id' => $school->id, 'name' => 'القسط السنوي'],
            ['total_minor' => '1000000.00', 'is_default' => true],
        );

        if ($feeType->installments()->doesntExist()) {
            $feeType->installments()->createMany([
                ['sort_order' => 1, 'due_date' => '2026-10-01', 'amount_minor' => '600000.00'],
                ['sort_order' => 2, 'due_date' => '2027-02-01', 'amount_minor' => '400000.00'],
            ]);
        }

        // One guardian per student, marked as the primary contact.
        Student::with('guardianLinks')->each(function (Student $student) use ($school) {
            if ($student->guardianLinks->isNotEmpty()) {
                return;
            }

            $guardian = Guardian::factory()->create(['school_id' => $school->id]);

            $student->guardianLinks()->create([
                'guardian_id' => $guardian->id,
                'relation' => GuardianRelation::Father,
                'is_primary' => true,
            ]);
        });
    }
}
