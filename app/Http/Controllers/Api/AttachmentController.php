<?php

namespace App\Http\Controllers\Api;

use App\Enums\AttachmentOwner;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttachmentResource;
use App\Models\AbsenceExcuse;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AttachmentController extends Controller
{
    /**
     * Upload against any owner. One table serves all of them (decision 12-a),
     * so the owner is named by type + id rather than by a separate endpoint.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'owner_type' => ['required', Rule::enum(AttachmentOwner::class)],
            'owner_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $owner = $this->resolveOwner(
            AttachmentOwner::from($data['owner_type']),
            (int) $data['owner_id'],
        );

        if (! $owner) {
            return response()->json(['message' => __('messages.not_found')], 404);
        }

        $file = $request->file('file');

        $attachment = Attachment::create([
            'school_id' => $request->user()->school_id,
            'owner_type' => $data['owner_type'],
            'owner_id' => $data['owner_id'],
            'path' => $file->store('attachments/'.$data['owner_type'], 'public'),
            'name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => __('messages.attachment.uploaded'),
            'data' => new AttachmentResource($attachment),
        ], 201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'owner_type' => ['required', Rule::enum(AttachmentOwner::class)],
            'owner_id' => ['required', 'integer'],
        ]);

        $attachments = Attachment::query()
            ->ofSchool($request->user()->school_id)
            ->where('owner_type', $data['owner_type'])
            ->where('owner_id', $data['owner_id'])
            ->orderByDesc('created_at')
            ->get();

        return AttachmentResource::collection($attachments);
    }

    public function destroy(Attachment $attachment): JsonResponse
    {
        $this->authorize('delete', $attachment);

        $attachment->delete();

        return response()->json(['message' => __('messages.attachment.deleted')]);
    }

    /**
     * The gallery: image attachments across the school, aggregated rather than
     * stored in a table of their own (decision 13-a). That is also why the
     * gallery screen has no upload button — images arrive with their owner.
     */
    public function gallery(Request $request): AnonymousResourceCollection
    {
        $images = Attachment::query()
            ->ofSchool($request->user()->school_id)
            ->images()
            ->when($request->filled('owner_type'), fn ($q) => $q->where('owner_type', $request->string('owner_type')))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 30))
            ->withQueryString();

        return AttachmentResource::collection($images);
    }

    /** The owner must exist and belong to the caller's school. */
    private function resolveOwner(AttachmentOwner $type, int $id): ?object
    {
        $schoolId = request()->user()->school_id;

        return match ($type) {
            AttachmentOwner::Post => Post::where('school_id', $schoolId)->find($id),
            AttachmentOwner::Message => Message::whereHas(
                'conversation',
                fn ($q) => $q->where('school_id', $schoolId),
            )->find($id),
            AttachmentOwner::Excuse => AbsenceExcuse::whereHas(
                'student',
                fn ($q) => $q->where('school_id', $schoolId),
            )->find($id),
            // Report cards arrive with the reports group.
            AttachmentOwner::ReportCard => null,
        };
    }
}
