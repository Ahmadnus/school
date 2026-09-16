<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\FeePayment;
use App\Models\FeePlan;
use App\Models\FeeType;
use App\Models\FeeTypeInstallment;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Services\FeeStatement;
use App\Services\PaymentRecorder;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * نظام الأقساط. كل اختبار هنا يحرس فلساً كان يمكن أن يضيع.
 */
class FeeSystemTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    private Student $student;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->admin = User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]);
        $this->student = Student::factory()->create(['school_id' => $this->school->id]);
        $this->year = AcademicYear::factory()->current()->create(['school_id' => $this->school->id]);

        Sanctum::actingAs($this->admin);
    }

    private function feeType(string $total, array $installments): FeeType
    {
        $type = FeeType::factory()->create([
            'school_id' => $this->school->id,
            'total_minor' => $total,
        ]);

        foreach ($installments as $index => [$due, $amount]) {
            FeeTypeInstallment::factory()->create([
                'fee_type_id' => $type->id,
                'sort_order' => $index + 1,
                'due_date' => $due,
                'amount_minor' => $amount,
            ]);
        }

        return $type->load('installments');
    }

    private function plan(array $overrides = []): FeePlan
    {
        $response = $this->postJson('/api/fee-plans', [
            'student_id' => $this->student->id,
            'academic_year_id' => $this->year->id,
            'total_amount' => '1000.00',
            'installments' => [
                ['due_date' => now()->addMonth()->toDateString(), 'amount' => '400.00'],
                ['due_date' => now()->addMonths(2)->toDateString(), 'amount' => '600.00'],
            ],
            ...$overrides,
        ])->assertCreated();

        return FeePlan::findOrFail($response->json('data.id'));
    }

    public function test_plan_from_a_fee_type_copies_its_instalments(): void
    {
        $type = $this->feeType('1000.00', [['2026-01-10', '400.00'], ['2026-02-10', '600.00']]);

        $this->postJson('/api/fee-plans', [
            'student_id' => $this->student->id,
            'academic_year_id' => $this->year->id,
            'fee_type_id' => $type->id,
        ])->assertCreated()
            ->assertJsonPath('data.total_amount', '1000.00')
            ->assertJsonPath('data.net_amount', '1000.00')
            ->assertJsonCount(2, 'data.installments');
    }

    public function test_a_discount_scales_the_instalments_so_they_still_add_up_to_the_net(): void
    {
        // الثغرة الأصلية: الأقساط تبقى 1000 والصافي 900، فيظهر الطالب مسدِّداً
        // وفي جدوله 100 لا يحملها قسط.
        $type = $this->feeType('1000.00', [['2026-01-10', '333.33'], ['2026-02-10', '333.33'], ['2026-03-10', '333.34']]);

        $response = $this->postJson('/api/fee-plans', [
            'student_id' => $this->student->id,
            'academic_year_id' => $this->year->id,
            'fee_type_id' => $type->id,
            'discount_amount' => '100.00',
            'discount_reason' => 'أخ ثانٍ في المدرسة',
        ])->assertCreated();

        $plan = FeePlan::with('installments')->findOrFail($response->json('data.id'));

        $this->assertSame('900.00', $plan->netAmount()->toDecimal());
        $this->assertSame(
            '900.00',
            Money::sum($plan->installments->map(fn ($i) => $i->amount()))->toDecimal(),
        );
    }

    public function test_custom_instalments_must_add_up_to_the_net(): void
    {
        $this->postJson('/api/fee-plans', [
            'student_id' => $this->student->id,
            'academic_year_id' => $this->year->id,
            'total_amount' => '1000.00',
            'installments' => [
                ['due_date' => '2026-01-10', 'amount' => '400.00'],
                ['due_date' => '2026-02-10', 'amount' => '500.00'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('installments');
    }

    public function test_a_discount_requires_a_reason(): void
    {
        $this->postJson('/api/fee-plans', [
            'student_id' => $this->student->id,
            'academic_year_id' => $this->year->id,
            'total_amount' => '1000.00',
            'discount_amount' => '100.00',
        ])->assertStatus(422)->assertJsonValidationErrors('discount_reason');
    }

    public function test_a_zero_discount_needs_no_reason_and_still_creates_the_plan(): void
    {
        // التطبيق يرسل حقل الخصم دائماً، وقيمته صفر في أغلب الخطط. ولمّا كان
        // تفريغه يتمّ بـ `$this->request->remove()` كان لا يمسّ بدن JSON إطلاقاً،
        // فيوقِظ `required_with` ويرتدّ كل طلب بـ 422 يطالب بسبب خصم لا وجود له:
        // لم تكن تُنشَأ خطة رسوم واحدة من التطبيق.
        $this->postJson('/api/fee-plans', [
            'student_id' => $this->student->id,
            'academic_year_id' => $this->year->id,
            'total_amount' => '1000.00',
            'discount_amount' => 0,
            'installments' => [
                ['due_date' => '2026-01-10', 'amount' => '1000.00'],
            ],
        ])->assertCreated();
    }

    public function test_a_payment_is_allocated_across_instalments_oldest_first(): void
    {
        $plan = $this->plan();

        // 500 تغطّي القسط الأول (400) وتضع 100 على الثاني، بلا أي قرار يدوي.
        $this->postJson("/api/fee-plans/{$plan->id}/payments", [
            'amount' => '500.00',
            'paid_on' => now()->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('plan.paid_amount', '500.00')
            ->assertJsonPath('plan.remaining_amount', '500.00')
            ->assertJsonPath('plan.installments.0.state', 'paid')
            ->assertJsonPath('plan.installments.1.paid_amount', '100.00')
            ->assertJsonPath('plan.installments.1.state', 'partially_paid');
    }

    public function test_a_payment_cannot_exceed_the_remaining_balance(): void
    {
        $plan = $this->plan();

        $this->postJson("/api/fee-plans/{$plan->id}/payments", [
            'amount' => '1000.01',
            'paid_on' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->assertSame('0.00', $plan->fresh()->paidAmount()->toDecimal());
    }

    public function test_a_future_dated_payment_is_rejected(): void
    {
        $plan = $this->plan();

        $this->postJson("/api/fee-plans/{$plan->id}/payments", [
            'amount' => '100.00',
            'paid_on' => now()->addDay()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('paid_on');
    }

    public function test_the_same_idempotency_key_records_one_payment_only(): void
    {
        $plan = $this->plan();
        $body = ['amount' => '300.00', 'paid_on' => now()->toDateString(), 'idempotency_key' => 'abc-123'];

        $first = $this->postJson("/api/fee-plans/{$plan->id}/payments", $body)->assertCreated();
        $second = $this->postJson("/api/fee-plans/{$plan->id}/payments", $body)->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, $plan->payments()->count());
        $this->assertSame('300.00', $plan->fresh()->paidAmount()->toDecimal());
    }

    public function test_receipts_are_numbered_sequentially_per_school(): void
    {
        $plan = $this->plan();

        foreach (['100.00', '200.00'] as $amount) {
            $this->postJson("/api/fee-plans/{$plan->id}/payments", [
                'amount' => $amount,
                'paid_on' => now()->toDateString(),
            ])->assertCreated();
        }

        $this->assertSame([1, 2], $plan->payments()->orderBy('id')->pluck('receipt_number')->all());
    }

    public function test_voiding_keeps_the_receipt_and_releases_the_balance(): void
    {
        $plan = $this->plan();

        $paymentId = $this->postJson("/api/fee-plans/{$plan->id}/payments", [
            'amount' => '400.00',
            'paid_on' => now()->toDateString(),
        ])->json('data.id');

        $this->postJson("/api/fee-payments/{$paymentId}/void", ['reason' => 'شيك مرتجع'])
            ->assertOk()
            ->assertJsonPath('data.is_voided', true)
            ->assertJsonPath('plan.paid_amount', '0.00')
            ->assertJsonPath('plan.remaining_amount', '1000.00');

        // الصف باقٍ بسببه: المبلغ خرج من الحساب لا من السجل.
        $payment = FeePayment::findOrFail($paymentId);
        $this->assertSame('شيك مرتجع', $payment->void_reason);
        $this->assertSame($this->admin->id, $payment->voided_by);
    }

    public function test_voiding_requires_a_reason(): void
    {
        $plan = $this->plan();

        $paymentId = $this->postJson("/api/fee-plans/{$plan->id}/payments", [
            'amount' => '100.00',
            'paid_on' => now()->toDateString(),
        ])->json('data.id');

        $this->postJson("/api/fee-payments/{$paymentId}/void", [])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_the_net_amount_cannot_drop_below_what_was_collected(): void
    {
        $plan = $this->plan();

        $this->postJson("/api/fee-plans/{$plan->id}/payments", [
            'amount' => '600.00',
            'paid_on' => now()->toDateString(),
        ])->assertCreated();

        $this->putJson("/api/fee-plans/{$plan->id}", ['total_amount' => '500.00'])
            ->assertStatus(422)->assertJsonValidationErrors('total_amount');

        $this->assertSame('1000.00', $plan->fresh()->totalAmount()->toDecimal());
    }

    public function test_a_plan_with_collected_payments_cannot_be_cancelled(): void
    {
        $plan = $this->plan();

        $this->postJson("/api/fee-plans/{$plan->id}/payments", [
            'amount' => '100.00',
            'paid_on' => now()->toDateString(),
        ])->assertCreated();

        $this->putJson("/api/fee-plans/{$plan->id}", ['status' => 'cancelled'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_editing_the_schedule_reallocates_existing_payments(): void
    {
        $plan = $this->plan();

        $this->postJson("/api/fee-plans/{$plan->id}/payments", [
            'amount' => '500.00',
            'paid_on' => now()->toDateString(),
        ])->assertCreated();

        $this->putJson("/api/fee-plans/{$plan->id}/installments", [
            'installments' => [
                ['due_date' => '2026-01-10', 'amount' => '250.00'],
                ['due_date' => '2026-02-10', 'amount' => '250.00'],
                ['due_date' => '2026-03-10', 'amount' => '500.00'],
            ],
        ])->assertOk()
            ->assertJsonPath('data.installments.0.state', 'paid')
            ->assertJsonPath('data.installments.1.state', 'paid')
            ->assertJsonPath('data.installments.2.paid_amount', '0.00');

        // المبلغ المقبوض لم يتغيّر، ولا توزيعه ضاع.
        $this->assertSame('500.00', $plan->fresh()->paidAmount()->toDecimal());
        $this->assertTrue(FeeStatement::allocationsBalance($this->school->id));
    }

    public function test_the_schedule_must_still_add_up_after_editing(): void
    {
        $plan = $this->plan();

        $this->putJson("/api/fee-plans/{$plan->id}/installments", [
            'installments' => [['due_date' => '2026-01-10', 'amount' => '900.00']],
        ])->assertStatus(422)->assertJsonValidationErrors('installments');
    }

    public function test_the_discount_filter_lists_only_students_with_a_discount(): void
    {
        $other = Student::factory()->create(['school_id' => $this->school->id]);

        $this->plan();
        $this->postJson('/api/fee-plans', [
            'student_id' => $other->id,
            'academic_year_id' => $this->year->id,
            'total_amount' => '1000.00',
            'discount_amount' => '250.00',
            'discount_reason' => 'ابن موظف',
            'installments' => [['due_date' => '2026-01-10', 'amount' => '750.00']],
        ])->assertCreated();

        $this->getJson('/api/fee-plans?has_discount=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student_id', $other->id)
            ->assertJsonPath('data.0.discount_amount', '250.00')
            ->assertJsonPath('data.0.discount_reason', 'ابن موظف');

        $this->getJson('/api/fee-plans?has_discount=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student_id', $this->student->id);
    }

    public function test_the_overdue_filter_finds_plans_with_a_late_instalment(): void
    {
        $this->plan([
            'installments' => [
                ['due_date' => now()->subMonth()->toDateString(), 'amount' => '400.00'],
                ['due_date' => now()->addMonth()->toDateString(), 'amount' => '600.00'],
            ],
        ]);

        $this->getJson('/api/fee-plans?overdue=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_overdue', true)
            ->assertJsonPath('data.0.installments.0.state', 'overdue');
    }

    public function test_the_statement_shows_every_line_with_a_running_balance(): void
    {
        $plan = $this->plan(['discount_amount' => '200.00', 'discount_reason' => 'منحة تفوّق', 'installments' => [
            ['due_date' => '2026-01-10', 'amount' => '800.00'],
        ]]);

        $this->postJson("/api/fee-plans/{$plan->id}/payments", [
            'amount' => '300.00',
            'paid_on' => now()->toDateString(),
        ])->assertCreated();

        $this->getJson("/api/students/{$this->student->id}/fee-statement")
            ->assertOk()
            ->assertJsonPath('data.billed', '1000.00')
            ->assertJsonPath('data.discount', '200.00')
            ->assertJsonPath('data.net', '800.00')
            ->assertJsonPath('data.paid', '300.00')
            ->assertJsonPath('data.remaining', '500.00')
            ->assertJsonCount(3, 'data.lines')
            ->assertJsonPath('data.lines.2.balance', '500.00');
    }

    public function test_the_summary_totals_match_the_plans(): void
    {
        $plan = $this->plan();

        $this->postJson("/api/fee-plans/{$plan->id}/payments", [
            'amount' => '250.00',
            'paid_on' => now()->toDateString(),
        ])->assertCreated();

        $this->getJson('/api/fees/summary')
            ->assertOk()
            ->assertJsonPath('data.billed', '1000.00')
            ->assertJsonPath('data.collected', '250.00')
            ->assertJsonPath('data.outstanding', '750.00')
            ->assertJsonPath('data.balanced', true);
    }

    public function test_many_small_payments_settle_the_plan_to_exactly_zero(): void
    {
        // 1000 على 3 أقساط غير قابلة للقسمة، مسدَّدة بدفعات صغيرة: أي انحراف
        // تقريب كان سيترك فلساً معلّقاً أو يمنع آخر دفعة.
        $plan = $this->plan(['installments' => [
            ['due_date' => '2026-01-10', 'amount' => '333.34'],
            ['due_date' => '2026-02-10', 'amount' => '333.33'],
            ['due_date' => '2026-03-10', 'amount' => '333.33'],
        ]]);

        for ($i = 0; $i < 100; $i++) {
            PaymentRecorder::record(
                plan: $plan,
                amount: Money::fromDecimal('10.00'),
                paidOn: now(),
                recordedBy: $this->admin,
            );
        }

        $plan = $plan->fresh(['installments.activeAllocations', 'activePayments']);

        $this->assertSame('1000.00', $plan->paidAmount()->toDecimal());
        $this->assertSame('0.00', $plan->remainingAmount()->toDecimal());
        $this->assertSame('0.00', $plan->overpaidAmount()->toDecimal());
        $this->assertSame('paid', $plan->paymentState()->value);
        $this->assertTrue($plan->installments->every(fn ($i) => $i->state()->value === 'paid'));
        $this->assertTrue(FeeStatement::allocationsBalance($this->school->id));
    }

    public function test_teachers_cannot_see_fees(): void
    {
        $teacher = User::factory()->role(UserRole::Teacher)->create(['school_id' => $this->school->id]);
        Sanctum::actingAs($teacher);

        $this->getJson('/api/fee-plans')->assertForbidden();
    }

    public function test_a_plan_of_another_school_is_not_reachable(): void
    {
        $plan = $this->plan();

        $outsider = User::factory()->role(UserRole::Admin)->create([
            'school_id' => School::factory()->create()->id,
        ]);
        Sanctum::actingAs($outsider);

        $this->postJson("/api/fee-plans/{$plan->id}/payments", [
            'amount' => '10.00',
            'paid_on' => now()->toDateString(),
        ])->assertForbidden();
    }
}
