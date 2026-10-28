<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\LedgerCategory;
use App\Models\LedgerEntry;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * المحاسبة — للإدارة فقط وضمن مدرسة المستخدم.
 *
 * إيرادات الأقساط تُقرأ من إيصالاتها (لا تُكرَّر هنا)، وهذا المسار يضيف
 * ما عداها: مصاريف وإيرادات أخرى. القيد لا يُحذف بل يُلغى بسبب.
 */
class AccountingController extends Controller
{
    private function schoolId(Request $request): int
    {
        abort_unless($request->user()->role->isAdministrative(), 403);

        return $request->user()->school_id;
    }

    public function categories(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId($request);
        LedgerCategory::ensureDefaults($schoolId);

        $rows = LedgerCategory::where('school_id', $schoolId)
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->string('kind')))
            ->orderBy('kind')->orderBy('name')->get();

        return response()->json(['data' => $rows->map(fn ($c) => [
            'id' => $c->id, 'kind' => $c->kind, 'name' => $c->name, 'is_active' => $c->is_active,
        ])]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId($request);
        $data = $request->validate([
            'kind' => ['required', Rule::in([LedgerCategory::INCOME, LedgerCategory::EXPENSE])],
            'name' => [
                'required', 'string', 'max:80',
                Rule::unique('ledger_categories')->where(
                    fn ($q) => $q->where('school_id', $schoolId)->where('kind', $request->input('kind'))
                ),
            ],
        ]);

        $category = LedgerCategory::create([...$data, 'school_id' => $schoolId]);

        return response()->json([
            'message' => __('accounting.category_saved'),
            'data' => ['id' => $category->id, 'kind' => $category->kind, 'name' => $category->name, 'is_active' => true],
        ], 201);
    }

    public function entries(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId($request);
        $data = $request->validate([
            'kind' => ['nullable', Rule::in([LedgerCategory::INCOME, LedgerCategory::EXPENSE])],
            'category_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = LedgerEntry::query()
            ->where('school_id', $schoolId)
            ->when($data['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))
            ->when($data['category_id'] ?? null, fn ($q, $v) => $q->where('ledger_category_id', $v))
            ->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate('entry_date', '>=', $v))
            ->when($data['to'] ?? null, fn ($q, $v) => $q->whereDate('entry_date', '<=', $v))
            ->with(['category:id,name', 'recorder:id,name'])
            ->orderByDesc('entry_date')->orderByDesc('id')
            ->paginate($data['per_page'] ?? 30);

        return response()->json([
            'data' => $page->getCollection()->map(fn (LedgerEntry $e) => $this->present($e))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId($request);
        $data = $request->validate([
            'ledger_category_id' => [
                'required',
                Rule::exists('ledger_categories', 'id')->where('school_id', $schoolId)->where('is_active', true),
            ],
            'entry_date' => ['required', 'date'],
            'amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ]);

        $amount = Money::fromDecimal($data['amount']);
        abort_if(! $amount->isPositive(), 422, __('validation.gt.numeric', ['attribute' => 'amount', 'value' => 0]));

        // ضغطة مزدوجة لا تسجّل القيد مرتين.
        if (! empty($data['idempotency_key'])) {
            $existing = LedgerEntry::where('school_id', $schoolId)
                ->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                return response()->json(['data' => $this->present($existing->load(['category', 'recorder']))]);
            }
        }

        $kind = LedgerCategory::findOrFail($data['ledger_category_id'])->kind;

        $entry = LedgerEntry::create([
            'school_id' => $schoolId,
            'ledger_category_id' => $data['ledger_category_id'],
            'kind' => $kind,
            'entry_date' => $data['entry_date'],
            'amount_minor' => $amount,
            'method' => $data['method'] ?? PaymentMethod::Cash->value,
            'reference' => $data['reference'] ?? null,
            'description' => $data['description'] ?? null,
            'idempotency_key' => $data['idempotency_key'] ?? null,
            'recorded_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => __('accounting.entry_created'),
            'data' => $this->present($entry->load(['category', 'recorder'])),
        ], 201);
    }

    public function void(Request $request, LedgerEntry $entry): JsonResponse
    {
        $schoolId = $this->schoolId($request);
        abort_unless($entry->school_id === $schoolId, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        abort_if($entry->isVoided(), 422, __('accounting.already_voided'));

        $entry->forceFill([
            'voided_at' => now(),
            'voided_by' => $request->user()->id,
            'void_reason' => $data['reason'],
        ])->save();

        return response()->json([
            'message' => __('accounting.entry_voided'),
            'data' => $this->present($entry->load(['category', 'recorder'])),
        ]);
    }

    /**
     * ملخّص فترة: إيرادات الأقساط + إيرادات أخرى − مصاريف = الصافي، مع
     * التوزيع حسب التصنيف والشهر. المبالغ كلها أعداد صحيحة بالفلس حتى آخر
     * خطوة؛ القيود الملغاة والإيصالات الملغاة مستبعدة.
     */
    public function summary(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId($request);
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $from = $data['from'] ?? now()->startOfYear()->toDateString();
        $to = $data['to'] ?? now()->toDateString();

        $entries = DB::table('ledger_entries')
            ->where('school_id', $schoolId)->whereNull('voided_at')
            ->whereBetween('entry_date', [$from, $to])
            ->get(['kind', 'ledger_category_id', 'entry_date', 'amount_minor']);

        $fees = DB::table('fee_payments')
            ->where('school_id', $schoolId)->whereNull('voided_at')
            ->whereBetween('paid_on', [$from, $to])
            ->get(['paid_on', 'amount_minor']);

        $income = 0;
        $expense = 0;
        $feeIncome = 0;
        $byCategory = [];
        $byMonth = [];

        foreach ($fees as $f) {
            $feeIncome += (int) $f->amount_minor;
            $m = substr((string) $f->paid_on, 0, 7);
            $byMonth[$m]['income'] = ($byMonth[$m]['income'] ?? 0) + (int) $f->amount_minor;
        }

        foreach ($entries as $e) {
            $minor = (int) $e->amount_minor;
            $m = substr((string) $e->entry_date, 0, 7);
            if ($e->kind === LedgerCategory::INCOME) {
                $income += $minor;
                $byMonth[$m]['income'] = ($byMonth[$m]['income'] ?? 0) + $minor;
            } else {
                $expense += $minor;
                $byMonth[$m]['expense'] = ($byMonth[$m]['expense'] ?? 0) + $minor;
            }
            $byCategory[$e->ledger_category_id] = ($byCategory[$e->ledger_category_id] ?? 0) + $minor;
        }

        $names = LedgerCategory::whereIn('id', array_keys($byCategory))->get()->keyBy('id');
        ksort($byMonth);

        $dec = fn (int $minor) => Money::fromMinor($minor)->toDecimal();

        return response()->json(['data' => [
            'from' => $from,
            'to' => $to,
            'fees_income' => $dec($feeIncome),
            'other_income' => $dec($income),
            'total_income' => $dec($feeIncome + $income),
            'expenses' => $dec($expense),
            'net' => $dec($feeIncome + $income - $expense),
            'by_category' => collect($byCategory)->map(fn (int $minor, int $id) => [
                'category_id' => $id,
                'name' => $names[$id]->name ?? '',
                'kind' => $names[$id]->kind ?? '',
                'total' => $dec($minor),
            ])->sortByDesc(fn ($r) => (float) $r['total'])->values(),
            'by_month' => collect($byMonth)->map(fn (array $v, string $month) => [
                'month' => $month,
                'income' => $dec($v['income'] ?? 0),
                'expense' => $dec($v['expense'] ?? 0),
            ])->values(),
        ]]);
    }

    /** @return array<string, mixed> */
    private function present(LedgerEntry $e): array
    {
        return [
            'id' => $e->id,
            'kind' => $e->kind,
            'category_id' => $e->ledger_category_id,
            'category' => $e->category?->name,
            'entry_date' => $e->entry_date?->toDateString(),
            'amount' => $e->amount()->toDecimal(),
            'method' => $e->method->value,
            'method_label' => $e->method->label(),
            'reference' => $e->reference,
            'description' => $e->description,
            'recorder_name' => $e->recorder?->name,
            'is_voided' => $e->isVoided(),
            'void_reason' => $e->void_reason,
        ];
    }
}
