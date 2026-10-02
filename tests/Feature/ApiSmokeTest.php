<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AbsenceExcuse;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\Conversation;
use App\Models\FeePlan;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\Guardian;
use App\Models\Post;
use App\Models\ReportCard;
use App\Models\Rubric;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentImport;
use App\Models\Subject;
use App\Models\User;
use App\Services\GuardianAccount;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * كل شاشة في التطبيقين تقرأ من مسار `GET` — فلا يجوز لأيّ منها أن يردّ ٥٠٠
 * لأيّ دور. ٤٠٣ و٤٠٤ ردّان صحيحان (دورٌ لا يملك، أو عنصرٌ لا يخصّه)، أمّا
 * ٥٠٠ فخطأٌ يراه المستخدم «حدث خطأ في الخادم» بلا سبب يفهمه.
 */
class ApiSmokeTest extends TestCase
{
    use RefreshDatabase;

    /** Route parameter → the model whose first row of the school fills it. */
    private const PARAMS = [
        'academic_year' => AcademicYear::class,
        'assessment' => Assessment::class,
        'assessment_type' => AssessmentType::class,
        'conversation' => Conversation::class,
        'excuse' => AbsenceExcuse::class,
        'fee_plan' => FeePlan::class,
        'fee_type' => FeeType::class,
        'grade' => Grade::class,
        'guardian' => Guardian::class,
        'import' => StudentImport::class,
        'post' => Post::class,
        'report_card' => ReportCard::class,
        'rubric' => Rubric::class,
        'section' => Section::class,
        'student' => Student::class,
        'subject' => Subject::class,
        'user' => User::class,
    ];

    private School $school;

    /** @var array<int, int> */
    private array $statuses = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->school = School::query()->firstOrFail();

        $student = Student::query()->where('school_id', $this->school->id)->firstOrFail();

        // ما لا يزرعه الـ seeder: عنصرٌ واحد من كل نوع تقرؤه شاشة.
        Conversation::factory()->create(['school_id' => $this->school->id]);
        Post::factory()->create(['school_id' => $this->school->id]);
        FeePlan::factory()->create(['student_id' => $student->id]);
        ReportCard::factory()->create(['student_id' => $student->id]);
        AbsenceExcuse::factory()->create(['student_id' => $student->id]);
        StudentImport::factory()->create(['school_id' => $this->school->id]);
    }

    private function role(UserRole $role): User
    {
        return User::query()
            ->where('school_id', $this->school->id)
            ->where('role', $role)
            ->firstOrFail();
    }

    private function guardianUser(): User
    {
        $student = Student::query()->where('school_id', $this->school->id)->firstOrFail();
        $guardian = $student->guardians()->first()
            ?? tap(Guardian::factory()->create(['school_id' => $this->school->id]), function (Guardian $g) use ($student) {
                $student->guardians()->attach($g->id, ['relation' => 'father', 'is_primary' => true]);
            });

        return GuardianAccount::for($guardian);
    }

    /** @return list<string> */
    private function failuresFor(User $user): array
    {
        Sanctum::actingAs($user);
        $failures = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/') || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uri = preg_replace_callback('/\{(\w+)\??\}/', function (array $m) {
                $model = self::PARAMS[$m[1]] ?? null;

                return (string) ($model ? ($model::query()->value('id') ?? 1) : 1);
            }, $route->uri());

            $response = $this->getJson('/'.$uri);
            $this->statuses[$response->status()] = ($this->statuses[$response->status()] ?? 0) + 1;

            if ($response->status() >= 500) {
                $failures[] = sprintf(
                    '%s → %d %s',
                    $uri,
                    $response->status(),
                    $response->exception?->getMessage() ?? '',
                );
            }
        }

        return $failures;
    }

    public function test_no_screen_breaks_for_an_administrator(): void
    {
        $failures = $this->failuresFor($this->role(UserRole::SuperAdmin));

        $this->assertSame([], $failures, implode("\n", $failures));
        // المدير يفتح كل شيء تقريباً: لو ردّت أغلب المسارات بغير ٢٠٠ فالفحص
        // لم يفحص شيئاً — جلسةٌ لم تُعتمد مثلاً.
        $this->assertGreaterThan(80, $this->statuses[200] ?? 0, (string) json_encode($this->statuses));
    }

    public function test_no_screen_breaks_for_a_teacher(): void
    {
        $failures = $this->failuresFor($this->role(UserRole::Teacher));

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    public function test_no_screen_breaks_for_a_guardian(): void
    {
        $failures = $this->failuresFor($this->guardianUser());

        $this->assertSame([], $failures, implode("\n", $failures));
    }
}
