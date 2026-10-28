<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\FeePayment;
use App\Models\FeePlan;
use App\Models\LedgerCategory;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * المحاسبة: المبالغ بالفلس بلا انحراف، الإلغاء لا الحذف، والعزل بين المدارس.
 */
class AccountingTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = School::factory()->create();
        $this->admin = User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]);
        Sanctum::actingAs($this->admin);
    }

    private function category(string $kind = 'expense'): int
    {
        return (int) $this->getJson('/api/accounting/categories?kind='.$kind)->assertOk()->json('data.0.id');
    }

    private function record(int $category, string $amount, string $date = '2026-10-10', array $extra = []): array
    {
        return $this->postJson('/api/accounting/entries', [
            'ledger_category_id' => $category,
            'entry_date' => $date,
            'amount' => $amount,
            ...$extra,
        ])->assertCreated()->json('data');
    }

    public function test_default_categories_are_created_once(): void
    {
        $this->getJson('/api/accounting/categories')->assertOk();
        $this->getJson('/api/accounting/categories')->assertOk();

        $this->assertSame(11, LedgerCategory::where('school_id', $this->school->id)->count());
    }

    public function test_the_summary_adds_up_to_the_fils_with_fee_income(): void
    {
        $expense = $this->category('expense');
        $income = $this->category('income');
        $this->record($expense, '100.10');
        $this->record($expense, '0.20');
        $this->record($income, '50.05');

        // إيصال أقساط حقيقي الشكل: يدخل في الإيرادات.
        $plan = FeePlan::factory()->create([
            'student_id' => Student::factory()->create(['school_id' => $this->school->id])->id,
        ]);
        FeePayment::factory()->create([
            'school_id' => $this->school->id,
            'fee_plan_id' => $plan->id,
            'paid_on' => '2026-10-05',
            'amount_minor' => '200.30',
        ]);

        $s = $this->getJson('/api/accounting/summary?from=2026-10-01&to=2026-10-31')->assertOk()->json('data');

        $this->assertSame('200.30', $s['fees_income']);
        $this->assertSame('50.05', $s['other_income']);
        $this->assertSame('250.35', $s['total_income']);
        $this->assertSame('100.30', $s['expenses']);
        $this->assertSame('150.05', $s['net']); // لا 0.1+0.2 العائمة
    }

    public function test_voiding_keeps_the_row_and_removes_it_from_the_totals(): void
    {
        $expense = $this->category('expense');
        $entry = $this->record($expense, '75.00');

        $this->postJson("/api/accounting/entries/{$entry['id']}/void", ['reason' => 'خطأ إدخال'])
            ->assertOk()->assertJsonPath('data.is_voided', true);
        $this->postJson("/api/accounting/entries/{$entry['id']}/void", ['reason' => 'مرة ثانية'])
            ->assertUnprocessable();

        $this->assertDatabaseHas('ledger_entries', ['id' => $entry['id']]);
        $s = $this->getJson('/api/accounting/summary?from=2026-01-01&to=2026-12-31')->json('data');
        $this->assertSame('0.00', $s['expenses']);
    }

    public function test_a_double_tap_with_the_same_key_records_once(): void
    {
        $expense = $this->category('expense');
        $this->record($expense, '10.00', '2026-10-10', ['idempotency_key' => 'abc']);
        $this->postJson('/api/accounting/entries', [
            'ledger_category_id' => $expense, 'entry_date' => '2026-10-10',
            'amount' => '10.00', 'idempotency_key' => 'abc',
        ])->assertOk();

        $this->assertSame(1, DB::table('ledger_entries')->count());
    }

    public function test_zero_and_malformed_amounts_are_rejected(): void
    {
        $expense = $this->category('expense');
        foreach (['0', '0.00', '-5', '1.234', 'abc'] as $bad) {
            $this->postJson('/api/accounting/entries', [
                'ledger_category_id' => $expense, 'entry_date' => '2026-10-10', 'amount' => $bad,
            ])->assertUnprocessable();
        }
        $this->assertSame(0, DB::table('ledger_entries')->count());
    }

    public function test_teachers_are_refused_and_other_schools_are_invisible(): void
    {
        $expense = $this->category('expense');
        $this->record($expense, '5.00');

        $other = School::factory()->create();
        $otherAdmin = User::factory()->role(UserRole::Admin)->create(['school_id' => $other->id]);
        Sanctum::actingAs($otherAdmin);
        $this->getJson('/api/accounting/entries')->assertOk()->assertJsonCount(0, 'data');
        // تصنيف مدرسة أخرى لا يصلح لقيد هنا.
        $this->postJson('/api/accounting/entries', [
            'ledger_category_id' => $expense, 'entry_date' => '2026-10-10', 'amount' => '1.00',
        ])->assertUnprocessable();

        $teacher = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);
        Sanctum::actingAs($teacher);
        $this->getJson('/api/accounting/summary')->assertForbidden();
        $this->getJson('/api/accounting/entries')->assertForbidden();
    }
}
