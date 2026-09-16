<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Guardian\AttachGuardianRequest;
use App\Http\Requests\Guardian\UpdateGuardianLinkRequest;
use App\Http\Resources\GuardianResource;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentGuardian;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class StudentGuardianController extends Controller
{
    public function index(Student $student): AnonymousResourceCollection
    {
        $this->authorize('view', $student);

        return GuardianResource::collection(
            $student->guardians()->orderByPivot('is_primary', 'desc')->orderBy('name')->get(),
        );
    }

    /**
     * Both modes of the guardian block in the student form: link an existing
     * guardian, or create one inline from name + phone.
     */
    public function store(AttachGuardianRequest $request, Student $student): JsonResponse
    {
        $this->authorize('update', $student);

        $data = $request->validated();

        $link = DB::transaction(function () use ($data, $request, $student) {
            $guardian = isset($data['guardian_id'])
                ? Guardian::findOrFail($data['guardian_id'])
                : Guardian::create([
                    'school_id' => $student->school_id,
                    'name' => $data['name'],
                    'phone' => $data['phone'],
                    'email' => $data['email'] ?? null,
                ]);

            return $student->guardianLinks()->create([
                'guardian_id' => $guardian->id,
                'relation' => $data['relation'],
                'is_primary' => $request->boolean('is_primary'),
            ]);
        });

        return response()->json([
            'message' => __('messages.guardian.linked'),
            'data' => new GuardianResource(
                $student->guardians()->wherePivot('id', $link->id)->firstOrFail(),
            ),
        ], 201);
    }

    public function update(UpdateGuardianLinkRequest $request, Student $student, Guardian $guardian): JsonResponse
    {
        $this->authorize('update', $student);

        $link = StudentGuardian::query()
            ->where('student_id', $student->id)
            ->where('guardian_id', $guardian->id)
            ->firstOrFail();

        $link->update([
            ...$request->safe()->except('is_primary'),
            ...$request->has('is_primary') ? ['is_primary' => $request->boolean('is_primary')] : [],
        ]);

        return response()->json([
            'message' => __('messages.guardian.link_updated'),
            'data' => new GuardianResource(
                $student->guardians()->wherePivot('id', $link->id)->firstOrFail(),
            ),
        ]);
    }

    public function destroy(Student $student, Guardian $guardian): JsonResponse
    {
        $this->authorize('update', $student);

        $student->guardianLinks()->where('guardian_id', $guardian->id)->delete();

        return response()->json(['message' => __('messages.guardian.unlinked')]);
    }
}
