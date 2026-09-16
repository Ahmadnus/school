<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentProfile\StoreStudentNoteRequest;
use App\Http\Resources\StudentNoteResource;
use App\Models\Student;
use App\Models\StudentNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Internal notes on a student. Staff only — StudentPolicy::viewNotes refuses
 * guardians and drivers, so nothing here can leak to the guardian app.
 */
class StudentNoteController extends Controller
{
    public function index(Request $request, Student $student): AnonymousResourceCollection
    {
        $this->authorize('viewNotes', $student);

        $notes = $student->notes()
            ->with('author')
            ->when($request->filled('search'), fn ($q) => $q->where('body', 'like', '%'.$request->string('search').'%'))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return StudentNoteResource::collection($notes);
    }

    public function store(StoreStudentNoteRequest $request, Student $student): JsonResponse
    {
        $this->authorize('manageNotes', $student);

        $note = $student->notes()->create([
            ...$request->validated(),
            'author_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => __('messages.student_note.created'),
            'data' => new StudentNoteResource($note->load('author')),
        ], 201);
    }

    public function update(StoreStudentNoteRequest $request, StudentNote $note): JsonResponse
    {
        $this->authorize('update', $note);

        $note->update($request->validated());

        return response()->json([
            'message' => __('messages.student_note.updated'),
            'data' => new StudentNoteResource($note->fresh('author')),
        ]);
    }

    public function destroy(StudentNote $note): JsonResponse
    {
        $this->authorize('delete', $note);

        $note->delete();

        return response()->json(['message' => __('messages.student_note.deleted')]);
    }
}
