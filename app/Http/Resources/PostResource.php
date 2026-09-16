<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'post_type_id' => $this->post_type_id,
            'author_id' => $this->author_id,
            'title' => $this->title,
            'body' => $this->body,
            'subject_id' => $this->subject_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'published_at' => $this->published_at,
            'requires_confirmation' => (bool) $this->requires_confirmation,
            // The current user's own receipt — `receipts` is eager-loaded
            // constrained to them, so this never fans out per row.
            'my_receipt' => $this->when($this->relationLoaded('receipts'), function () use ($request) {
                $mine = $this->receipts->firstWhere('user_id', $request->user()?->id);

                return $mine ? [
                    'read_at' => $mine->read_at,
                    'confirmed_at' => $mine->confirmed_at,
                ] : null;
            }),
            // Author/admin only, on show(): how far the announcement reached.
            'receipt_stats' => $this->when(isset($this->receipt_stats), fn () => $this->receipt_stats),
            'type' => new PostTypeResource($this->whenLoaded('type')),
            'author' => new UserResource($this->whenLoaded('author')),
            'subject' => new SubjectResource($this->whenLoaded('subject')),
            'targets' => PostTargetResource::collection($this->whenLoaded('targets')),
            'approvals' => PostApprovalResource::collection($this->whenLoaded('approvals')),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
