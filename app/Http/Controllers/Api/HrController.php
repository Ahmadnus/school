<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\EmployeeProfile;
use App\Models\LeaveRequest;
use App\Models\LedgerCategory;
use App\Models\LedgerEntry;
use App\Models\SalaryPayment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * الموارد البشرية: ملف الموظف، الإجازات، والرواتب.
 *
 * - الإدارة تدير كل شيء ضمن مدرستها؛ الموظف العادي يطلب إجازة لنفسه ويرى
 *   إجازاته فقط، ولا يرى رواتب أحد.
 * - صرف الراتب يكتب قيد مصروف «رواتب» في المحاسبة في العملية نفسها، وإلغاؤه
 *   يُلغي القيد معه، فلا يختلف كشف الرواتب عن الدفاتر.
 * - الصافي = الأساسي + العلاوة − الخصم، يُحسب هنا بالفلس.
 */
class HrController extends Controller
{
    private function admin(Request $request): int
    {
        abort_unless($request->user()->role->isAdministrative(), 403);

        return $request->user()->school_id;
    }

    /** موظفو المدرسة: كل دور غير وليّ الأمر. */
    private function staff(int $schoolId)
    {
        return User::query()
            ->where('school_id', $schoolId)
            ->where('role', '!=', UserRole::Guardian->value);
    }

    public function employees(Request $request): JsonResponse
    {
        $schoolId = $this->admin($request);
        $month = now()->format('Y-m');

        $users = $this->staff($schoolId)->orderBy('name')->get();
        $profiles = EmployeeProfile::where('school_id', $schoolId)->get()->keyBy('user_id');
        $paid = SalaryPayment::where('school_id', $schoolId)->where('month', $month)
            ->whereNull('voided_at')->pluck('user_id')->flip();

        return response()->json(['data' => $users->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'role' => $u->role->value,
            'role_label' => $u->role->label(),
            'job_title' => ($profiles[$u->id] ?? null)?->job_title,
            'hired_on' => ($profiles[$u->id] ?? null)?->hired_on?->toDateString(),
            'base_salary' => ($profiles[$u->id] ?? null)?->baseSalary()->toDecimal() ?? '0.00',
            'paid_this_month' => isset($paid[$u->id]),
        ])->values()]);
    }

    public function saveProfile(Request $request, User $user): JsonResponse
    {
        $schoolId = $this->admin($request);
        abort_unless($user->school_id === $schoolId && ! $user->role->isGuardian(), 404);

        $data = $request->validate([
            'job_title' => ['nullable', 'string', 'max:120'],
            'hired_on' => ['nullable', 'date'],
            'base_salary' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $profile = EmployeeProfile::firstOrNew(['user_id' => $user->id]);
        $profile->fill([
            'school_id' => $schoolId,
            'job_title' => $data['job_title'] ?? $profile->job_title,
            'hired_on' => $data['hired_on'] ?? $profile->hired_on,
            'base_salary_minor' => Money::fromDecimal($data['base_salary'] ?? '0'),
            'notes' => $data['notes'] ?? $profile->notes,
        ])->save();

        return response()->json(['data' => [
            'id' => $user->id,
            'job_title' => $profile->job_title,
            'base_salary' => $profile->baseSalary()->toDecimal(),
        ]]);
    }

    public function leaves(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user->role->isGuardian(), 403);

        $rows = LeaveRequest::query()
            ->where('school_id', $user->school_id)
            ->when(! $user->role->isAdministrative(), fn ($q) => $q->where('user_id', $user->id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->with('user:id,name')
            ->orderByDesc('id')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (LeaveRequest $l) => $this->presentLeave($l))->values()]);
    }

    public function requestLeave(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user->role->isGuardian(), 403);

        $data = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'type' => ['required', Rule::in(LeaveRequest::TYPES)],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        // الإدارة تسجّل لأي موظف في مدرستها؛ غيرها لنفسه فقط.
        $targetId = $user->id;
        if ($user->role->isAdministrative() && ! empty($data['user_id'])) {
            $target = $this->staff($user->school_id)->findOrFail($data['user_id']);
            $targetId = $target->id;
        }

        $leave = LeaveRequest::create([
            'school_id' => $user->school_id,
            'user_id' => $targetId,
            'type' => $data['type'],
            'from_date' => $data['from_date'],
            'to_date' => $data['to_date'],
            'reason' => $data['reason'] ?? null,
            'status' => 'pending',
        ]);

        return response()->json(['data' => $this->presentLeave($leave->load('user:id,name'))], 201);
    }

    public function decideLeave(Request $request, LeaveRequest $leave): JsonResponse
    {
        $schoolId = $this->admin($request);
        abort_unless($leave->school_id === $schoolId, 404);
        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        abort_unless($leave->status === 'pending', 422, 'already decided');

        $leave->forceFill([
            'status' => $data['status'],
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
            'decision_note' => $data['note'] ?? null,
        ])->save();

        return response()->json(['data' => $this->presentLeave($leave->load('user:id,name'))]);
    }

    /** كشف رواتب شهر: لكل موظف أساسيّه وهل صُرف وبكم. */
    public function payroll(Request $request): JsonResponse
    {
        $schoolId = $this->admin($request);
        $data = $request->validate(['month' => ['nullable', 'regex:/^\d{4}-\d{2}$/']]);
        $month = $data['month'] ?? now()->format('Y-m');

        $profiles = EmployeeProfile::where('school_id', $schoolId)->get()->keyBy('user_id');
        $payments = SalaryPayment::where('school_id', $schoolId)->where('month', $month)
            ->whereNull('voided_at')->get()->keyBy('user_id');

        $rows = $this->staff($schoolId)->orderBy('name')->get()->map(function (User $u) use ($profiles, $payments) {
            $base = ($profiles[$u->id] ?? null)?->baseSalary() ?? Money::zero();
            $paid = $payments[$u->id] ?? null;

            return [
                'user_id' => $u->id,
                'name' => $u->name,
                'job_title' => ($profiles[$u->id] ?? null)?->job_title,
                'base_salary' => $base->toDecimal(),
                'payment_id' => $paid?->id,
                'paid' => $paid !== null,
                'net' => $paid?->net_minor->toDecimal(),
            ];
        });

        $minor = fn ($rows, $key) => $rows->sum(fn ($r) => (int) round(((float) ($r[$key] ?? 0)) * 100));

        return response()->json(['data' => [
            'month' => $month,
            'rows' => $rows->values(),
            'paid_total' => Money::fromMinor($payments->sum(fn ($p) => $p->net_minor->minor))->toDecimal(),
            'due_total' => Money::fromMinor($minor($rows->where('paid', false), 'base_salary'))->toDecimal(),
        ]]);
    }

    public function paySalary(Request $request): JsonResponse
    {
        $schoolId = $this->admin($request);
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'bonus' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'deduction' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'paid_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $employee = $this->staff($schoolId)->findOrFail($data['user_id']);
        $profile = EmployeeProfile::where('user_id', $employee->id)->first();
        $base = $profile?->baseSalary() ?? Money::zero();
        abort_if(! $base->isPositive(), 422, __('hr.no_base_salary'));

        $bonus = Money::fromDecimal($data['bonus'] ?? '0');
        $deduction = Money::fromDecimal($data['deduction'] ?? '0');
        $net = $base->plus($bonus)->minus($deduction);
        abort_if($net->isNegative(), 422, __('hr.net_negative'));

        $payment = DB::transaction(function () use ($request, $schoolId, $data, $employee, $base, $bonus, $deduction, $net) {
            // قفل صفّ الموظف يمنع صرفاً مزدوجاً من طلبين متزامنين.
            User::whereKey($employee->id)->lockForUpdate()->first();

            $exists = SalaryPayment::where('user_id', $employee->id)
                ->where('month', $data['month'])->whereNull('voided_at')->exists();
            abort_if($exists, 422, __('hr.already_paid'));

            $paidOn = $data['paid_on'] ?? now()->toDateString();
            $entry = LedgerEntry::create([
                'school_id' => $schoolId,
                'ledger_category_id' => LedgerCategory::system($schoolId, 'expense.salaries')->id,
                'kind' => LedgerCategory::EXPENSE,
                'entry_date' => $paidOn,
                'amount_minor' => $net,
                'method' => $data['method'] ?? PaymentMethod::Cash->value,
                'description' => $employee->name.' — '.$data['month'],
                'recorded_by' => $request->user()->id,
            ]);

            return SalaryPayment::create([
                'school_id' => $schoolId,
                'user_id' => $employee->id,
                'month' => $data['month'],
                'base_minor' => $base,
                'bonus_minor' => $bonus,
                'deduction_minor' => $deduction,
                'net_minor' => $net,
                'method' => $data['method'] ?? PaymentMethod::Cash->value,
                'paid_on' => $paidOn,
                'note' => $data['note'] ?? null,
                'ledger_entry_id' => $entry->id,
                'recorded_by' => $request->user()->id,
            ]);
        });

        return response()->json(['data' => [
            'id' => $payment->id,
            'net' => $payment->net_minor->toDecimal(),
            'month' => $payment->month,
        ]], 201);
    }

    public function voidSalary(Request $request, SalaryPayment $payment): JsonResponse
    {
        $schoolId = $this->admin($request);
        abort_unless($payment->school_id === $schoolId, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        abort_if($payment->isVoided(), 422, __('hr.already_voided'));

        DB::transaction(function () use ($request, $payment, $data) {
            $payment->forceFill([
                'voided_at' => now(),
                'voided_by' => $request->user()->id,
                'void_reason' => $data['reason'],
            ])->save();

            LedgerEntry::whereKey($payment->ledger_entry_id)->whereNull('voided_at')->get()
                ->each(fn (LedgerEntry $e) => $e->forceFill([
                    'voided_at' => now(),
                    'voided_by' => $request->user()->id,
                    'void_reason' => $data['reason'],
                ])->save());
        });

        return response()->json(['data' => ['id' => $payment->id, 'is_voided' => true]]);
    }

    /** @return array<string, mixed> */
    private function presentLeave(LeaveRequest $l): array
    {
        return [
            'id' => $l->id,
            'user_id' => $l->user_id,
            'user_name' => $l->user?->name,
            'type' => $l->type,
            'from_date' => $l->from_date?->toDateString(),
            'to_date' => $l->to_date?->toDateString(),
            'days' => $l->from_date && $l->to_date ? $l->from_date->diffInDays($l->to_date) + 1 : null,
            'reason' => $l->reason,
            'status' => $l->status,
            'decision_note' => $l->decision_note,
        ];
    }
}
