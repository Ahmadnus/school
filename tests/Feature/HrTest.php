<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\LedgerEntry;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * الموارد البشرية: الراتب بالفلس، قيده المحاسبي يتبعه، ولا صرف مزدوج.
 */
class HrTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = School::factory()->create();
        $this->admin = User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]);
        $this->teacher = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);
        Sanctum::actingAs($this->admin);
    }

    private function setSalary(string $amount): void
    {
        $this->putJson("/api/hr/employees/{$this->teacher->id}/profile", [
            'job_title' => 'معلّم رياضيات', 'base_salary' => $amount,
        ])->assertOk();
    }

    private function pay(array $extra = [])
    {
        return $this->postJson('/api/hr/payroll/pay', [
            'user_id' => $this->teacher->id, 'month' => '2026-10', ...$extra,
        ]);
    }

    public function test_paying_a_salary_computes_the_net_in_fils_and_posts_an_expense(): void
    {
        $this->setSalary('500.10');

        $this->pay(['bonus' => '20.20', 'deduction' => '0.30'])
            ->assertCreated()->assertJsonPath('data.net', '520.00');

        $entry = LedgerEntry::firstOrFail();
        $this->assertSame('expense', $entry->kind);
        $this->assertSame('520.00', $entry->amount()->toDecimal());
        $this->assertSame('expense.salaries', $entry->category->system_key);

        $s = $this->getJson('/api/accounting/summary?from=2026-01-01&to=2026-12-31')->json('data');
        $this->assertSame('520.00', $s['expenses']);
    }

    public function test_the_same_month_cannot_be_paid_twice(): void
    {
        $this->setSalary('300.00');
        $this->pay()->assertCreated();
        $this->pay()->assertUnprocessable();

        $this->assertSame(1, LedgerEntry::count());
    }

    public function test_voiding_a_salary_voids_its_ledger_entry_and_allows_repaying(): void
    {
        $this->setSalary('300.00');
        $id = $this->pay()->json('data.id');

        $this->postJson("/api/hr/payroll/{$id}/void", ['reason' => 'خطأ'])->assertOk();

        $this->assertNotNull(LedgerEntry::firstOrFail()->voided_at);
        $s = $this->getJson('/api/accounting/summary?from=2026-01-01&to=2026-12-31')->json('data');
        $this->assertSame('0.00', $s['expenses']);

        $this->pay()->assertCreated();
    }

    public function test_no_base_salary_or_a_deduction_above_pay_is_refused(): void
    {
        $this->pay()->assertUnprocessable();

        $this->setSalary('100.00');
        $this->pay(['deduction' => '150.00'])->assertUnprocessable();
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_leave_flow_staff_request_admin_decides_and_staff_see_only_their_own(): void
    {
        $other = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);

        Sanctum::actingAs($this->teacher);
        $leave = $this->postJson('/api/hr/leaves', [
            'type' => 'sick', 'from_date' => '2026-11-01', 'to_date' => '2026-11-03',
        ])->assertCreated()->assertJsonPath('data.days', 3)->assertJsonPath('data.status', 'pending')->json('data');
        $this->postJson("/api/hr/leaves/{$leave['id']}/decide", ['status' => 'approved'])->assertForbidden();

        Sanctum::actingAs($other);
        $this->postJson('/api/hr/leaves', [
            'type' => 'annual', 'from_date' => '2026-12-01', 'to_date' => '2026-12-02',
        ])->assertCreated();
        $this->getJson('/api/hr/leaves')->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/hr/leaves')->assertJsonCount(2, 'data');
        $this->postJson("/api/hr/leaves/{$leave['id']}/decide", ['status' => 'approved'])
            ->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/hr/leaves/{$leave['id']}/decide", ['status' => 'rejected'])->assertUnprocessable();
    }

    public function test_only_admins_see_payroll_and_other_schools_staff_are_unreachable(): void
    {
        $foreign = User::factory()->role(UserRole::Teacher)->create([
            'school_id' => School::factory()->create()->id,
        ]);
        $this->putJson("/api/hr/employees/{$foreign->id}/profile", ['base_salary' => '10'])->assertNotFound();
        $this->postJson('/api/hr/payroll/pay', ['user_id' => $foreign->id, 'month' => '2026-10'])->assertNotFound();

        Sanctum::actingAs($this->teacher);
        $this->getJson('/api/hr/payroll')->assertForbidden();
        $this->getJson('/api/hr/employees')->assertForbidden();
    }
}
