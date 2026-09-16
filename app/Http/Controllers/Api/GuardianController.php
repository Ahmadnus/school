<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Guardian\StoreGuardianRequest;
use App\Http\Requests\Guardian\UpdateGuardianRequest;
use App\Http\Resources\GuardianResource;
use App\Models\Guardian;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GuardianController extends Controller
{
    /** The guardians tab: search only, no filter chips. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Guardian::class);

        $guardians = Guardian::query()
            ->ofSchool($request->user()->school_id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($sub) => $sub->where('name', 'like', $term)->orWhere('phone', 'like', $term));
            })
            ->withCount('students')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return GuardianResource::collection($guardians);
    }

    public function store(StoreGuardianRequest $request): JsonResponse
    {
        $this->authorize('create', Guardian::class);

        $guardian = Guardian::create([
            ...$request->validated(),
            'school_id' => $request->user()->school_id,
        ]);

        return response()->json([
            'message' => __('messages.guardian.created'),
            'data' => new GuardianResource($guardian),
        ], 201);
    }

    public function show(Guardian $guardian): GuardianResource
    {
        $this->authorize('view', $guardian);

        return new GuardianResource(
            $guardian->load('students.currentEnrollment.section.grade')->loadCount('students'),
        );
    }

    public function update(UpdateGuardianRequest $request, Guardian $guardian): JsonResponse
    {
        $this->authorize('update', $guardian);

        $guardian->update($request->validated());

        return response()->json([
            'message' => __('messages.guardian.updated'),
            'data' => new GuardianResource($guardian->fresh()),
        ]);
    }

    public function destroy(Guardian $guardian): JsonResponse
    {
        $this->authorize('delete', $guardian);

        if ($guardian->links()->exists()) {
            return response()->json(['message' => __('messages.guardian.has_students')], 422);
        }

        $guardian->delete();

        return response()->json(['message' => __('messages.guardian.deleted')]);
    }
}
