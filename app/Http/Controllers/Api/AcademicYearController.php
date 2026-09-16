<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\StoreAcademicYearRequest;
use App\Http\Requests\Academic\UpdateAcademicYearRequest;
use App\Http\Resources\AcademicYearResource;
use App\Models\AcademicYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AcademicYearController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AcademicYear::class);

        $years = AcademicYear::query()
            ->ofSchool($request->user()->school_id)
            ->with('terms')
            ->withCount('terms')
            ->orderByDesc('start_date')
            ->get();

        return AcademicYearResource::collection($years);
    }

    /** The active year — the anchor the whole app reads from. */
    public function current(Request $request): AcademicYearResource
    {
        $this->authorize('viewAny', AcademicYear::class);

        $year = AcademicYear::query()
            ->ofSchool($request->user()->school_id)
            ->current()
            ->with('terms')
            ->firstOrFail();

        return new AcademicYearResource($year);
    }

    public function store(StoreAcademicYearRequest $request): JsonResponse
    {
        $this->authorize('create', AcademicYear::class);

        $year = AcademicYear::create([
            ...$request->safe()->except('is_current'),
            'school_id' => $request->user()->school_id,
        ]);

        if ($request->boolean('is_current')) {
            $year->markAsCurrent();
        }

        return response()->json([
            'message' => __('messages.academic_year.created'),
            'data' => new AcademicYearResource($year->fresh('terms')),
        ], 201);
    }

    public function show(AcademicYear $academicYear): AcademicYearResource
    {
        $this->authorize('view', $academicYear);

        return new AcademicYearResource($academicYear->load('terms'));
    }

    public function update(UpdateAcademicYearRequest $request, AcademicYear $academicYear): JsonResponse
    {
        $this->authorize('update', $academicYear);

        $academicYear->update($request->safe()->except('is_current'));

        if ($request->boolean('is_current')) {
            $academicYear->markAsCurrent();
        }

        return response()->json([
            'message' => __('messages.academic_year.updated'),
            'data' => new AcademicYearResource($academicYear->fresh('terms')),
        ]);
    }

    public function markCurrent(AcademicYear $academicYear): JsonResponse
    {
        $this->authorize('update', $academicYear);

        $academicYear->markAsCurrent();

        return response()->json([
            'message' => __('messages.academic_year.marked_current'),
            'data' => new AcademicYearResource($academicYear->fresh('terms')),
        ]);
    }

    public function destroy(AcademicYear $academicYear): JsonResponse
    {
        $this->authorize('delete', $academicYear);

        if ($academicYear->enrollments()->exists()) {
            return response()->json(['message' => __('messages.academic_year.has_enrollments')], 422);
        }

        $academicYear->delete();

        return response()->json(['message' => __('messages.academic_year.deleted')]);
    }
}
