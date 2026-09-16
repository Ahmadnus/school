<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SectionResource;
use App\Models\Section;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The sections a supervisor oversees (decision 7-a).
 *
 * The `supervisor_scopes` table shipped with the schema but had no endpoints,
 * so the "supervision scope" section of the staff form had nothing to call.
 */
class SupervisorScopeController extends Controller
{
    public function index(User $user): AnonymousResourceCollection
    {
        $this->authorize('view', $user);

        return SectionResource::collection($user->supervisedSections);
    }

    public function store(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $data = $request->validate([
            'section_id' => [
                'required',
                Rule::exists('sections', 'id'),
            ],
        ]);

        // Idempotent: re-adding the same section is not an error, it is a no-op.
        $user->supervisedSections()->syncWithoutDetaching([$data['section_id']]);

        return response()->json([
            'message' => __('messages.supervisor_scope.added'),
            'data' => new SectionResource(Section::findOrFail($data['section_id'])),
        ], 201);
    }

    public function destroy(User $user, Section $section): JsonResponse
    {
        $this->authorize('update', $user);

        $user->supervisedSections()->detach($section->id);

        return response()->json([
            'message' => __('messages.supervisor_scope.removed'),
        ]);
    }
}
