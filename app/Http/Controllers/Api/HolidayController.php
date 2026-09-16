<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Report\StoreHolidayRequest;
use App\Http\Resources\HolidayResource;
use App\Models\Holiday;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HolidayController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Holiday::class);

        $holidays = Holiday::query()
            ->ofSchool($request->user()->school_id)
            ->when(
                $request->filled('academic_year_id'),
                fn ($q) => $q->where('academic_year_id', $request->integer('academic_year_id')),
            )
            ->orderBy('start_date')
            ->get();

        return HolidayResource::collection($holidays);
    }

    public function store(StoreHolidayRequest $request): JsonResponse
    {
        $this->authorize('create', Holiday::class);

        $holiday = Holiday::create([
            ...$request->validated(),
            'school_id' => $request->user()->school_id,
        ]);

        return response()->json([
            'message' => __('messages.holiday.created'),
            'data' => new HolidayResource($holiday),
        ], 201);
    }

    public function update(StoreHolidayRequest $request, Holiday $holiday): JsonResponse
    {
        $this->authorize('update', $holiday);

        $holiday->update($request->validated());

        return response()->json([
            'message' => __('messages.holiday.updated'),
            'data' => new HolidayResource($holiday->fresh()),
        ]);
    }

    public function destroy(Holiday $holiday): JsonResponse
    {
        $this->authorize('delete', $holiday);

        $holiday->delete();

        return response()->json(['message' => __('messages.holiday.deleted')]);
    }
}
