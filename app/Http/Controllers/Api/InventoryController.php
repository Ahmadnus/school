<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * الأصول الثابتة والمخزون (للإدارة فقط).
 *
 * - الرصيد لا يُكتب مباشرة: كل تغيير حركة موقَّعة تُسجَّل مع الرصيد بعدها،
 *   داخل عملية بقفل على الصنف، ولا يُسمح بإخراج أكثر من الرصيد.
 * - الحركات لا تُحذف؛ التصحيح بتسوية إلى رصيد مُعدّ.
 */
class InventoryController extends Controller
{
    private function admin(Request $request): int
    {
        abort_unless($request->user()->role->isAdministrative(), 403);

        return $request->user()->school_id;
    }

    // ---------------------------------------------------------------- assets

    public function assets(Request $request): JsonResponse
    {
        $schoolId = $this->admin($request);

        $rows = Asset::query()->where('school_id', $schoolId)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('code', 'like', $term));
            })
            ->with('holder:id,name')->orderBy('name')->limit(500)->get();

        return response()->json(['data' => $rows->map(fn (Asset $a) => $this->presentAsset($a))->values()]);
    }

    public function storeAsset(Request $request): JsonResponse
    {
        $schoolId = $this->admin($request);
        $asset = Asset::create($this->assetData($request, $schoolId) + ['school_id' => $schoolId]);

        return response()->json(['data' => $this->presentAsset($asset->load('holder:id,name'))], 201);
    }

    public function updateAsset(Request $request, int $id): JsonResponse
    {
        $schoolId = $this->admin($request);
        $asset = Asset::where('school_id', $schoolId)->findOrFail($id);
        $asset->update($this->assetData($request, $schoolId));

        return response()->json(['data' => $this->presentAsset($asset->load('holder:id,name'))]);
    }

    private function assetData(Request $request, int $schoolId): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:40'],
            'category' => ['nullable', 'string', 'max:80'],
            'location' => ['nullable', 'string', 'max:120'],
            'purchased_on' => ['nullable', 'date'],
            'cost' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            'status' => ['nullable', Rule::in(Asset::STATUSES)],
            'assigned_to' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        if (! empty($data['assigned_to'])) {
            $exists = User::where('school_id', $schoolId)->whereKey($data['assigned_to'])->exists();
            if (! $exists) {
                throw ValidationException::withMessages(['assigned_to' => __('inventory.bad_holder')]);
            }
        }

        $out = collect($data)->except('cost')->all();
        if (array_key_exists('cost', $data)) {
            $out['cost_minor'] = Money::fromDecimal($data['cost'] ?? '0');
        }

        return $out;
    }

    private function presentAsset(Asset $a): array
    {
        return [
            'id' => $a->id,
            'name' => $a->name,
            'code' => $a->code,
            'category' => $a->category,
            'location' => $a->location,
            'purchased_on' => $a->purchased_on?->toDateString(),
            'cost' => $a->cost_minor->toDecimal(),
            'status' => $a->status,
            'assigned_to' => $a->assigned_to,
            'holder_name' => $a->holder?->name,
            'notes' => $a->notes,
        ];
    }

    // ----------------------------------------------------------------- stock

    public function items(Request $request): JsonResponse
    {
        $schoolId = $this->admin($request);

        $rows = InventoryItem::query()->where('school_id', $schoolId)
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('sku', 'like', $term));
            })
            ->orderBy('name')->limit(500)->get();

        return response()->json(['data' => $rows->map(fn (InventoryItem $i) => $this->presentItem($i))->values()]);
    }

    public function storeItem(Request $request): JsonResponse
    {
        $schoolId = $this->admin($request);
        $data = $this->itemData($request);
        $opening = (int) ($request->validate(['quantity' => ['nullable', 'integer', 'min:0', 'max:1000000']])['quantity'] ?? 0);

        $item = DB::transaction(function () use ($data, $schoolId, $opening, $request) {
            $item = InventoryItem::create($data + ['school_id' => $schoolId]);
            if ($opening > 0) {
                $this->move($item, 'in', $opening, __('inventory.opening'), $request->user()->id);
            }

            return $item->refresh();
        });

        return response()->json(['data' => $this->presentItem($item)], 201);
    }

    public function updateItem(Request $request, int $id): JsonResponse
    {
        $schoolId = $this->admin($request);
        $item = InventoryItem::where('school_id', $schoolId)->findOrFail($id);
        $item->update($this->itemData($request));

        return response()->json(['data' => $this->presentItem($item->refresh())]);
    }

    private function itemData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'sku' => ['nullable', 'string', 'max:40'],
            'unit' => ['nullable', 'string', 'max:30'],
            'min_quantity' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }

    /** إدخال / إخراج بكمية موجبة، أو تسوية إلى رصيد مُعدّ. */
    public function moveStock(Request $request, int $id): JsonResponse
    {
        $schoolId = $this->admin($request);
        $data = $request->validate([
            'type' => ['required', Rule::in(['in', 'out', 'adjust'])],
            'quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        if ($data['type'] !== 'adjust' && $data['quantity'] < 1) {
            throw ValidationException::withMessages(['quantity' => __('inventory.qty_positive')]);
        }

        $item = DB::transaction(function () use ($schoolId, $id, $data, $request) {
            $item = InventoryItem::where('school_id', $schoolId)->lockForUpdate()->findOrFail($id);
            $delta = match ($data['type']) {
                'in' => $data['quantity'],
                'out' => -$data['quantity'],
                'adjust' => $data['quantity'] - $item->quantity,
            };
            if ($item->quantity + $delta < 0) {
                throw ValidationException::withMessages(['quantity' => __('inventory.insufficient')]);
            }
            if ($delta !== 0) {
                $this->move($item, $data['type'], $delta, $data['reason'] ?? null, $request->user()->id);
            }

            return $item->refresh();
        });

        return response()->json(['data' => $this->presentItem($item)]);
    }

    public function movements(Request $request, int $id): JsonResponse
    {
        $schoolId = $this->admin($request);
        $item = InventoryItem::where('school_id', $schoolId)->findOrFail($id);

        $rows = StockMovement::where('item_id', $item->id)->orderByDesc('id')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn (StockMovement $m) => [
            'id' => $m->id,
            'type' => $m->type,
            'delta' => $m->delta,
            'balance_after' => $m->balance_after,
            'reason' => $m->reason,
            'at' => $m->created_at?->toIso8601String(),
        ])->values()]);
    }

    public function summary(Request $request): JsonResponse
    {
        $schoolId = $this->admin($request);
        $assets = Asset::where('school_id', $schoolId)->get();
        $items = InventoryItem::where('school_id', $schoolId)->get();

        return response()->json(['data' => [
            'assets_active' => $assets->where('status', 'active')->count(),
            'assets_maintenance' => $assets->where('status', 'maintenance')->count(),
            'assets_value' => Money::fromMinor($assets->where('status', '!=', 'retired')
                ->sum(fn (Asset $a) => $a->cost_minor->minor))->toDecimal(),
            'items' => $items->count(),
            'low_stock' => $items->filter(fn (InventoryItem $i) => $i->isLow())->count(),
        ]]);
    }

    /** يكتب الحركة ويحدّث الرصيد المخزَّن؛ $delta موقَّع. */
    private function move(InventoryItem $item, string $type, int $delta, ?string $reason, int $by): void
    {
        $balance = $item->quantity + $delta;
        StockMovement::create([
            'school_id' => $item->school_id, 'item_id' => $item->id, 'type' => $type,
            'delta' => $delta, 'balance_after' => $balance, 'reason' => $reason, 'recorded_by' => $by,
        ]);
        $item->forceFill(['quantity' => $balance])->save();
    }

    private function presentItem(InventoryItem $i): array
    {
        return [
            'id' => $i->id,
            'name' => $i->name,
            'sku' => $i->sku,
            'unit' => $i->unit,
            'quantity' => $i->quantity,
            'min_quantity' => $i->min_quantity,
            'low' => $i->isLow(),
            'notes' => $i->notes,
        ];
    }
}
