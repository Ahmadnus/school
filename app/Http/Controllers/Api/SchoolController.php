<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\School\UpdateSchoolRequest;
use App\Http\Resources\SchoolResource;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SchoolController extends Controller
{
    /** The authenticated user's own school. */
    public function show(Request $request): SchoolResource
    {
        $school = $request->user()->school;
        $this->authorize('view', $school);

        return new SchoolResource($school);
    }

    public function update(UpdateSchoolRequest $request): JsonResponse
    {
        $school = $request->user()->school;
        $this->authorize('update', $school);

        $data = $request->safe()->except('logo');

        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('schools/logos', 'public');
        }

        $school->update($data);

        return response()->json([
            'message' => __('messages.school.updated'),
            'data' => new SchoolResource($school->fresh()),
        ]);
    }
}
