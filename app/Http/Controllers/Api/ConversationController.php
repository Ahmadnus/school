<?php

namespace App\Http\Controllers\Api;

use App\Enums\ConversationStatus;
use App\Enums\ConversationType;
use App\Enums\NotificationApp;
use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Message\StoreConversationRequest;
use App\Http\Requests\Message\StoreMessageRequest;
use App\Http\Requests\Message\UpdateFollowUpRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\NotificationGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ConversationController extends Controller
{
    /** The two tabs: guardians and staff. Only threads the user is in. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Conversation::class);

        $conversations = Conversation::query()
            ->ofSchool($request->user()->school_id)
            ->forUser($request->user()->id)
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            // Follow-up workflow chips (staff).
            ->when($request->boolean('needs_follow_up'), fn ($q) => $q->where('needs_follow_up', true))
            ->when($request->boolean('important'), fn ($q) => $q->where('is_important', true))
            ->when($request->boolean('follow_up_due'), fn ($q) => $q
                ->where('needs_follow_up', true)
                ->whereDate('follow_up_at', '<=', now()->toDateString()))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($sub) => $sub
                    ->where('title', 'like', $term)
                    ->orWhereHas('student', fn ($s) => $s
                        ->where('first_name', 'like', $term)
                        ->orWhere('last_name', 'like', $term)));
            })
            ->with(['student', 'participantRecords', 'lastMessage'])
            ->withUnreadCountFor($request->user()->id)
            ->orderByDesc('last_message_at')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return ConversationResource::collection($conversations);
    }

    /** Starting a thread always carries its first message. */
    public function store(StoreConversationRequest $request): JsonResponse
    {
        $this->authorize('create', Conversation::class);

        $user = $request->user();

        $conversation = DB::transaction(function () use ($request, $user) {
            $conversation = Conversation::create([
                'school_id' => $user->school_id,
                'type' => $request->input('type'),
                'student_id' => $request->input('student_id'),
                'title' => $request->input('title'),
                'last_message_at' => now(),
            ]);

            // The opener is always a participant.
            $ids = collect($request->input('participant_ids', []))
                ->push($user->id)
                ->unique();

            foreach ($ids as $id) {
                $conversation->participantRecords()->create([
                    'user_id' => $id,
                    'last_read_at' => $id === $user->id ? now() : null,
                ]);
            }

            $conversation->messages()->create([
                'sender_id' => $user->id,
                'body' => $request->input('body'),
                'sent_at' => now(),
            ]);

            return $conversation;
        });

        return response()->json([
            'message' => __('messages.conversation.created'),
            'data' => new ConversationResource(
                $conversation->load(['student', 'participants', 'participantRecords', 'lastMessage']),
            ),
        ], 201);
    }

    public function show(Conversation $conversation): ConversationResource
    {
        $this->authorize('view', $conversation);

        return new ConversationResource(
            $conversation->load(['student', 'participants', 'participantRecords', 'lastMessage']),
        );
    }

    public function messages(Request $request, Conversation $conversation): AnonymousResourceCollection
    {
        $this->authorize('view', $conversation);

        // `after_id` يجعل الاستطلاع الدوري رخيصاً: يردّ الجديد وحده، وغالباً
        // لا شيء — فبدله تنزل ثلاثون رسالة بمرفقاتها كل عشر ثوانٍ لمجرّد
        // السؤال «هل وصل شيء؟». وهذا ما يبقي الشات حيّاً حيث لا سوكِت:
        // استضافة مشتركة لا تُشغّل Reverb.
        if ($request->filled('after_id')) {
            $messages = $conversation->messages()
                ->with(['sender', 'attachments'])
                ->where('id', '>', $request->integer('after_id'))
                ->orderBy('id')
                // سقفٌ يمنع صفحةً ضخمة لمن غاب طويلاً؛ الباقي يأتي بطلب تالٍ.
                ->limit(50)
                ->get();

            return MessageResource::collection($messages);
        }

        $messages = $conversation->messages()
            ->with(['sender', 'attachments'])
            ->orderByDesc('sent_at')
            ->paginate($request->integer('per_page', 30));

        return MessageResource::collection($messages);
    }

    public function sendMessage(StoreMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('reply', $conversation);

        if ($conversation->status === ConversationStatus::Closed) {
            return response()->json(['message' => __('messages.conversation.closed')], 422);
        }

        $message = DB::transaction(function () use ($request, $conversation) {
            $message = $conversation->messages()->create([
                'sender_id' => $request->user()->id,
                'body' => (string) $request->input('body', ''),
                'sent_at' => now(),
            ]);

            // Files travel in the same request, so the live broadcast and the
            // notification already know about them (a second upload call
            // would reach the other side before its attachments existed).
            foreach ($request->file('files', []) as $file) {
                $message->attachments()->create([
                    'school_id' => $conversation->school_id,
                    'path' => $file->store('attachments/message', 'public'),
                    'name' => $file->getClientOriginalName(),
                    'mime' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'uploaded_by' => $request->user()->id,
                ]);
            }

            $conversation->update(['last_message_at' => $message->sent_at]);

            $conversation->participantRecords()
                ->where('user_id', $request->user()->id)
                ->update(['last_read_at' => now()]);

            return $message;
        });

        // Live delivery to the thread, then a notification for every other
        // participant who has not muted it.
        $message->load(['sender', 'attachments']);

        // Live delivery is best-effort: the message is stored and the
        // notifications still go out even when the Reverb server is down.
        try {
            MessageSent::dispatch($message);
        } catch (\Throwable $e) {
            Log::warning('Realtime broadcast failed: '.$e->getMessage(), ['message' => $message->id]);
        }
        $this->notifyParticipants($conversation, $message, $request->user());

        return response()->json([
            'message' => __('messages.message.sent'),
            'data' => new MessageResource($message),
        ], 201);
    }

    /**
     * A new message notifies the other participants, subject to the mute flag
     * on the thread and to both notification switches (decision 8-a).
     */
    private function notifyParticipants(
        Conversation $conversation,
        Message $message,
        User $sender,
    ): void {
        $participants = $conversation->participantRecords()
            ->where('user_id', '!=', $sender->id)
            ->where('is_muted', false)
            ->with('user')
            ->get();

        foreach ($participants as $participant) {
            if ($participant->user === null) {
                continue;
            }

            NotificationGate::notify(
                user: $participant->user,
                key: 'message_received',
                title: $sender->full_name,
                // A file-only message still needs a readable preview.
                body: $message->body !== ''
                    ? Str::limit($message->body, 120)
                    : ($message->attachments->first()?->name ?? ''),
                refId: $conversation->id,
                // The guardian reads the guardian-app switch, staff the staff one.
                app: $participant->user->role->isGuardian()
                    ? NotificationApp::Guardian
                    : NotificationApp::Staff,
            );
        }
    }

    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $conversation->participantRecords()
            ->where('user_id', $request->user()->id)
            ->update(['last_read_at' => now()]);

        return response()->json(['message' => __('messages.conversation.read')]);
    }

    /** The ⋮ menu's mute toggle — per participant, not per user globally. */
    public function toggleMute(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $participant = $conversation->participantRecords()
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $participant->update(['is_muted' => ! $participant->is_muted]);

        return response()->json([
            'message' => $participant->is_muted
                ? __('messages.conversation.muted')
                : __('messages.conversation.unmuted'),
            'data' => ['is_muted' => $participant->is_muted],
        ]);
    }

    /**
     * Flag a thread for follow-up / mark it important. Staff only: this is how
     * "the parent asked for a meeting" becomes "follow up on Thursday".
     */
    public function updateFollowUp(UpdateFollowUpRequest $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('flag', $conversation);

        $data = $request->validated();

        // Clearing the flag clears its date and note too.
        if (array_key_exists('needs_follow_up', $data) && ! $data['needs_follow_up']) {
            $data['follow_up_at'] = null;
            $data['follow_up_note'] = null;
        }

        $conversation->update([...$data, 'flagged_by' => $request->user()->id]);

        return response()->json([
            'message' => __('messages.conversation.follow_up_saved'),
            'data' => new ConversationResource(
                $conversation->fresh(['student', 'participants', 'participantRecords', 'lastMessage']),
            ),
        ]);
    }

    public function updateStatus(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('updateStatus', $conversation);

        $data = $request->validate([
            'status' => ['required', Rule::enum(ConversationStatus::class)],
        ]);

        $conversation->update($data);

        return response()->json([
            'message' => __('messages.conversation.status_updated'),
            'data' => new ConversationResource($conversation->fresh(['participantRecords'])),
        ]);
    }

    /** The guardian tab's "new message" flow picks a student first. */
    public function types(): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                fn (ConversationType $type) => ['value' => $type->value, 'label' => $type->label()],
                ConversationType::cases(),
            ),
        ]);
    }
}
