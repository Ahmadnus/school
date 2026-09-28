<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $me = $request->user();
        $mine = $this->relationLoaded('participantRecords')
            ? $this->participantRecords->firstWhere('user_id', $me?->id)
            : null;

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'student_id' => $this->student_id,
            'title' => $this->title,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'last_message_at' => $this->last_message_at,
            // Follow-up workflow (staff-only fields; the guardian app ignores them).
            'needs_follow_up' => (bool) $this->needs_follow_up,
            'follow_up_at' => $this->follow_up_at?->toDateString(),
            'follow_up_overdue' => $this->isFollowUpOverdue(),
            'is_important' => (bool) $this->is_important,
            'follow_up_note' => $this->follow_up_note,
            'is_muted' => $mine?->is_muted ?? false,
            'last_read_at' => $mine?->last_read_at,
            // علامة «قُرئت» للطرف الآخر: أقدم وقتٍ قرأ فيه كلّ من عداي.
            // منها يرسم التطبيق ✓✓ على رسائلي التي أُرسلت قبله.
            'others_last_read_at' => $this->when(
                $this->relationLoaded('participantRecords') && (bool) $me,
                fn () => $this->othersReadWatermark($me->id),
            ),
            'unread_count' => $this->when(
                (bool) $me,
                fn () => $this->unreadCountFor($me->id),
            ),
            // أسماء الطرف الآخر — منها يسمّي التطبيق المحادثة كما في واتساب:
            // بالأشخاص لا بعنوانٍ كتبه أحدهم مرّة. تُحسب هنا لأنّ الخادم وحده
            // يعرف من «أنا»، فلا يحتاج العميل أن يرشّح نفسه من القائمة.
            'counterparts' => $this->when(
                $this->relationLoaded('participants') && (bool) $me,
                fn () => $this->participants
                    ->where('id', '!=', $me->id)
                    ->map(fn ($user) => [
                        'id' => $user->id,
                        'full_name' => $user->full_name,
                        'role_label' => $user->role->label(),
                    ])
                    ->values(),
            ),
            'student' => new StudentResource($this->whenLoaded('student')),
            'participants' => UserResource::collection($this->whenLoaded('participants')),
            'last_message' => new MessageResource($this->whenLoaded('lastMessage')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
