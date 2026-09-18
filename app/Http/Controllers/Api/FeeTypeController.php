<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Fee\StoreFeeTypeRequest;
use App\Http\Requests\Fee\UpdateFeeTypeRequest;
use App\Http\Resources\FeeTypeResource;
use App\Models\FeeType;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class FeeTypeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', FeeType::class);

        $types = FeeType::query()
            ->ofSchool($request->user()->school_id)
            ->when($request->filled('grade_id'), fn ($q) => $q->where('grade_id', $request->integer('grade_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->has('is_transport'), fn ($q) => $q->where('is_transport', $request->boolean('is_transport')))
            ->with(['grade', 'subject', 'installments'])
            ->withCount('plans')
            ->orderBy('name')
            ->get();

        return FeeTypeResource::collection($types);
    }

    public function store(StoreFeeTypeRequest $request): JsonResponse
    {
        $this->authorize('create', FeeType::class);

        $type = DB::transaction(function () use ($request) {
            $type = FeeType::create([
                ...$request->safe()->except(['installments', 'total_amount']),
                'school_id' => $request->user()->school_id,
                // العقد مع التطبيق `total_amount` والعمود `total_minor`؛ تمرير الأول
                // كما هو يجعل لارافيل يُسقطه **بصمت** (ليس في fillable)، فيُحفظ
                // النوع بصفر مهما كتب المستخدم — ومنه ترث كل خطة صفراً.
                'total_minor' => Money::fromDecimal($request->input('total_amount', 0)),
            ]);

            $this->makeSoleDefault($type);

            $this->replaceInstallments($type, $request->input('installments', []));

            return $type;
        });

        return response()->json([
            'message' => __('messages.fee_type.created'),
            'data' => new FeeTypeResource($type->load(['grade', 'subject', 'installments'])),
        ], 201);
    }

    /**
     * النوع الافتراضي للصف **واحد لا أكثر**.
     *
     * منه ترث كل خطة تُنشَأ عند تسجيل طالب في هذا الصف؛ ووجود اثنين يعني أن
     * الاختيار يقع على أيّهما وجدته الاستعلامة أوّلاً — فيدفع طالب سعر غير سعره
     * ولا شيء في الواجهة يكشف ذلك.
     *
     * يُقيَّد بالصف: لكل صف افتراضيّه، والأنواع بلا صف مجموعة واحدة.
     */
    private function makeSoleDefault(FeeType $type): void
    {
        if (! $type->is_default) {
            return;
        }

        FeeType::query()
            ->where('school_id', $type->school_id)
            ->where('grade_id', $type->grade_id)
            ->whereKeyNot($type->getKey())
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    public function show(FeeType $feeType): FeeTypeResource
    {
        $this->authorize('view', $feeType);

        return new FeeTypeResource($feeType->load(['grade', 'subject', 'installments'])->loadCount('plans'));
    }

    /**
     * Editing a type never reaches the plans already built from it: their
     * instalments are their own copies (decision 2-a).
     */
    public function update(UpdateFeeTypeRequest $request, FeeType $feeType): JsonResponse
    {
        $this->authorize('update', $feeType);

        DB::transaction(function () use ($request, $feeType) {
            $feeType->update([
                ...$request->safe()->except(['installments', 'total_amount']),
                ...($request->has('total_amount')
                    ? ['total_minor' => Money::fromDecimal($request->input('total_amount', 0))]
                    : []),
            ]);

            $this->makeSoleDefault($feeType);

            if ($request->has('installments')) {
                $this->replaceInstallments($feeType, $request->input('installments', []));
            }
        });

        return response()->json([
            'message' => __('messages.fee_type.updated'),
            'data' => new FeeTypeResource($feeType->fresh(['grade', 'subject', 'installments'])),
        ]);
    }

    public function destroy(FeeType $feeType): JsonResponse
    {
        $this->authorize('delete', $feeType);

        if ($feeType->plans()->exists()) {
            return response()->json(['message' => __('messages.fee_type.in_use')], 422);
        }

        $feeType->delete();

        return response()->json(['message' => __('messages.fee_type.deleted')]);
    }

    private function replaceInstallments(FeeType $type, array $rows): void
    {
        $type->installments()->delete();

        foreach ($rows as $position => $row) {
            $type->installments()->create([
                'sort_order' => $position + 1,
                'due_date' => $row['due_date'],
                'amount_minor' => Money::fromDecimal($row['amount']),
                'notes' => $row['notes'] ?? null,
            ]);
        }
    }
}
