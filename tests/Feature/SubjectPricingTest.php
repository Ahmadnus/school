<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\FeePlan;
use App\Models\FeeType;
use App\Models\Grade;
use App\Models\School;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * سعر لكل مادة، والإجمالي يُجمع وحده.
 *
 * كان على المسجِّل أن يحسب بيده: ثلاث مواد × سعرها، ثم يكتب الناتج. وحسبةٌ
 * يدويّة في المال تُخطئ، والخطأ لا يظهر إلاّ في كشف حساب وليّ أمر.
 */
class SubjectPricingTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Section $section;

    /** @var array<string, Subject> */
    private array $subjects = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $year = AcademicYear::factory()->current()->create(['school_id' => $this->school->id]);
        $term = Term::factory()->create(['academic_year_id' => $year->id]);
        $grade = Grade::factory()->create(['school_id' => $this->school->id]);
        $this->section = Section::factory()->create([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
        ]);

        foreach (['رياضيات' => 1_000_000, 'فيزياء' => 1_500_000, 'رسم' => null] as $name => $price) {
            $subject = Subject::factory()->create([
                'grade_id' => $grade->id,
                'term_id' => $term->id,
                'name' => $name,
            ]);
            $this->subjects[$name] = $subject;

            if ($price !== null) {
                FeeType::factory()->create([
                    'school_id' => $this->school->id,
                    'subject_id' => $subject->id,
                    'name' => 'سعر '.$name,
                    'total_minor' => $price,
                ]);
            }
        }

        Sanctum::actingAs(
            User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]),
        );
    }

    /** @param array<int, string> $names */
    private function enrol(array $names, ?float $written = null): FeePlan
    {
        $this->postJson('/api/students', array_filter([
            'first_name' => 'طالب',
            'last_name' => 'جديد'.count($names).($written ?? ''),
            'section_id' => $this->section->id,
            'scope' => 'full_year',
            'enrolled_at' => now()->toDateString(),
            'plan_mode' => 'subjects',
            'subject_ids' => array_map(fn (string $n) => $this->subjects[$n]->id, $names),
            'plan_total_amount' => $written,
        ], fn ($value) => $value !== null))->assertCreated();

        return FeePlan::query()->latest('id')->firstOrFail();
    }

    public function test_the_total_is_the_sum_of_chosen_subject_prices(): void
    {
        $plan = $this->enrol(['رياضيات', 'فيزياء']);

        $this->assertSame('2500000.00', (string) $plan->totalAmount()->toDecimal());
    }

    public function test_one_subject_costs_its_own_price(): void
    {
        // لا سعر موحّد مفترض: الفيزياء أغلى من الرياضيات، وهذا كلّ الغرض.
        $plan = $this->enrol(['فيزياء']);

        $this->assertSame('1500000.00', (string) $plan->totalAmount()->toDecimal());
    }

    public function test_a_subject_without_a_price_counts_zero_and_does_not_block(): void
    {
        // خطةٌ ناقصة يراها المحاسب فيُكملها، أَولى من رفض تسجيل الطالب.
        $plan = $this->enrol(['رياضيات', 'رسم']);

        $this->assertSame('1000000.00', (string) $plan->totalAmount()->toDecimal());
    }

    public function test_a_written_amount_still_wins(): void
    {
        // الاتّفاق الخاصّ (خصم، حالة استثنائية) يبقى ممكناً.
        $plan = $this->enrol(['رياضيات', 'فيزياء'], 2_000_000);

        $this->assertSame('2000000.00', (string) $plan->totalAmount()->toDecimal());
    }

    public function test_the_price_is_listed_with_the_subject(): void
    {
        // الشاشة تجمع أمام المستخدم وهو يؤشّر، فتحتاج السعر مع كل مادة.
        $response = $this->getJson('/api/subjects')->assertOk();

        $prices = collect($response->json('data'))
            ->pluck('price', 'name');

        $this->assertSame('1000000.00', $prices['رياضيات']);
        $this->assertNull($prices['رسم']);
    }

    public function test_subjects_with_no_price_at_all_are_refused_not_silently_free(): void
    {
        // الثغرة التي يسدّها هذا الفحص: بلا سعرٍ ولا مبلغ مكتوب يصير
        // الإجمالي صفراً، والخطة الصفرية تُتخطّى عمداً — فينجح التسجيل
        // ظاهريّاً ويختفي الطالب من صفحة الرسوم. مالٌ لا يطالب به أحد.
        $this->postJson('/api/students', [
            'first_name' => 'طالب',
            'last_name' => 'بلا تسعير',
            'section_id' => $this->section->id,
            'scope' => 'full_year',
            'enrolled_at' => now()->toDateString(),
            'plan_mode' => 'subjects',
            'subject_ids' => [$this->subjects['رسم']->id],
        ])->assertStatus(422)->assertJsonValidationErrors('plan_total_amount');
    }

    public function test_one_priced_subject_is_enough_to_pass(): void
    {
        // الرفض مشروط بانعدام السعر كلّه، لا بنقصه: مادة مسعّرة وأخرى بلا
        // سعر تمضي، وتظهر الخطة ناقصةً ليُكملها المحاسب.
        $plan = $this->enrol(['رسم', 'فيزياء']);

        $this->assertSame('1500000.00', (string) $plan->totalAmount()->toDecimal());
    }
}
