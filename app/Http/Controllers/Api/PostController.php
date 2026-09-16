<?php

namespace App\Http\Controllers\Api;

use App\Enums\PostStatus;
use App\Enums\TargetScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Post\ReviewPostRequest;
use App\Http\Requests\Post\StorePostRequest;
use App\Http\Requests\Post\UpdatePostRequest;
use App\Http\Resources\PostResource;
use App\Http\Resources\PostReceiptResource;
use App\Models\Post;
use App\Models\PostReceipt;
use App\Models\PostType;
use App\Services\PostAudience;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class PostController extends Controller
{
    /**
     * The posts list: the mine/school switch plus the type, targeting-scope
     * and subject filter chips.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $isGuardian = $user->role->isGuardian();

        $posts = Post::query()
            ->ofSchool($user->school_id)
            // A guardian never authors posts, so "mine" is meaningless for
            // them; they see published posts whose targets reach a child of
            // theirs, and nothing else.
            ->when($isGuardian, fn ($q) => $q->published()->whereIn(
                'id',
                PostAudience::postIdsForGuardian($user),
            ))
            ->when(
                ! $isGuardian && $request->input('source', 'mine') === 'mine',
                fn ($q) => $q->where('author_id', $user->id),
            )
            // Other people's posts are only visible once published.
            ->when(
                $request->input('source') === 'school' && ! $user->role->isAdministrative(),
                fn ($q) => $q->where(fn ($sub) => $sub->published()->orWhere('author_id', $user->id)),
            )
            ->when($request->filled('post_type_id'), fn ($q) => $q->where('post_type_id', $request->integer('post_type_id')))
            ->when($request->filled('subject_id'), fn ($q) => $q->where('subject_id', $request->integer('subject_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('scope'), fn ($q) => $q->targeting(
                TargetScope::from($request->string('scope')->toString()),
                $request->filled('target_id') ? $request->integer('target_id') : null,
            ))
            ->with(['type', 'author', 'subject', 'targets', 'attachments'])
            ->with(['receipts' => fn ($r) => $r->where('user_id', $user->id)])
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return PostResource::collection($posts);
    }

    /** The approvals inbox behind the app-bar icon. */
    public function pending(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Post::class);

        $posts = Post::query()
            ->ofSchool($request->user()->school_id)
            ->pending()
            ->with(['type', 'author', 'targets'])
            ->orderBy('created_at')
            ->paginate($request->integer('per_page', 20));

        return PostResource::collection($posts);
    }

    /**
     * Creating honours two things: the type's min_role chips, and whether that
     * type requires approval (decision 9-a).
     */
    public function store(StorePostRequest $request): JsonResponse
    {
        $user = $request->user();
        $type = PostType::findOrFail($request->integer('post_type_id'));

        if (! $type->is_enabled) {
            return response()->json(['message' => __('messages.post.type_disabled')], 422);
        }

        if (! $type->allows($user)) {
            return response()->json(['message' => __('messages.post.not_allowed_type')], 403);
        }

        $status = match (true) {
            ! $request->boolean('submit') => PostStatus::Draft,
            $type->requires_approval => PostStatus::Pending,
            default => PostStatus::Published,
        };

        $post = DB::transaction(function () use ($request, $user, $type, $status) {
            $post = Post::create([
                'school_id' => $user->school_id,
                'post_type_id' => $type->id,
                'author_id' => $user->id,
                'title' => $request->input('title'),
                'body' => $request->input('body'),
                'subject_id' => $request->input('subject_id'),
                'status' => $status,
                'requires_confirmation' => $request->boolean('requires_confirmation'),
                'published_at' => $status === PostStatus::Published ? now() : null,
            ]);

            $this->replaceTargets($post, $request->input('targets', []));

            return $post;
        });

        // The same fan-out as submit(): a type that needs no approval is
        // published right here, so its audience must be told right here —
        // previously only submit()/review() notified, and a post created
        // with submit=true reached nobody.
        if ($status === PostStatus::Published) {
            PostAudience::notify($post);
        } elseif ($status === PostStatus::Pending) {
            PostAudience::notifyApprovers($post);
        }

        return response()->json([
            'message' => match ($status) {
                PostStatus::Pending => __('messages.post.submitted'),
                PostStatus::Published => __('messages.post.published'),
                default => __('messages.post.created'),
            },
            'data' => new PostResource($post->load(['type', 'author', 'targets'])),
        ], 201);
    }

    public function show(Request $request, Post $post): PostResource
    {
        $this->authorize('view', $post);

        $post->load([
            'type', 'author', 'subject', 'targets', 'approvals.reviewer', 'attachments',
            'receipts' => fn ($r) => $r->where('user_id', $request->user()->id),
        ]);

        // Reach counters for the author and administrators. "Audience" is the
        // number of guardian accounts the targets resolve to — only people who
        // can actually open the app; nothing pretends to know about delivery.
        if ($this->canSeeReceipts($request->user(), $post)) {
            $post->setAttribute('receipt_stats', [
                'audience' => PostAudience::guardianUsersFor($post)->count(),
                'read' => PostReceipt::query()->where('post_id', $post->id)->whereNotNull('read_at')->count(),
                'confirmed' => PostReceipt::query()->where('post_id', $post->id)->whereNotNull('confirmed_at')->count(),
            ]);
        }

        return new PostResource($post);
    }

    /** Opening the detail screen records a read; idempotent. */
    public function markRead(Request $request, Post $post): JsonResponse
    {
        $this->authorize('view', $post);

        $receipt = PostReceipt::query()->firstOrNew(['post_id' => $post->id, 'user_id' => $request->user()->id]);
        $receipt->read_at ??= now();
        $receipt->save();

        return response()->json(['message' => __('messages.post.read'), 'data' => $this->receiptState($receipt)]);
    }

    /** Explicit "I have read this" on posts that ask for it. */
    public function confirm(Request $request, Post $post): JsonResponse
    {
        $this->authorize('view', $post);

        if (! $post->requires_confirmation) {
            return response()->json(['message' => __('messages.post.no_confirmation')], 422);
        }

        $receipt = PostReceipt::query()->firstOrNew(['post_id' => $post->id, 'user_id' => $request->user()->id]);
        $receipt->read_at ??= now();
        $receipt->confirmed_at ??= now();
        $receipt->save();

        return response()->json(['message' => __('messages.post.confirmed'), 'data' => $this->receiptState($receipt)]);
    }

    /** Who read / confirmed — for the author and administrators. */
    public function receipts(Request $request, Post $post): AnonymousResourceCollection
    {
        $this->authorize('view', $post);
        abort_unless($this->canSeeReceipts($request->user(), $post), 403, __('messages.unauthorized'));

        $receipts = $post->receipts()
            ->with('user')
            ->when($request->string('filter')->toString() === 'confirmed', fn ($q) => $q->whereNotNull('confirmed_at'))
            ->orderByDesc('confirmed_at')
            ->orderByDesc('read_at')
            ->paginate($request->integer('per_page', 30))
            ->withQueryString();

        return PostReceiptResource::collection($receipts);
    }

    private function canSeeReceipts(\App\Models\User $user, Post $post): bool
    {
        return $post->author_id === $user->id || $user->role->isAdministrative();
    }

    /** @return array{read_at: ?\Illuminate\Support\Carbon, confirmed_at: ?\Illuminate\Support\Carbon} */
    private function receiptState(PostReceipt $receipt): array
    {
        return ['read_at' => $receipt->read_at, 'confirmed_at' => $receipt->confirmed_at];
    }

    public function update(UpdatePostRequest $request, Post $post): JsonResponse
    {
        $this->authorize('update', $post);

        DB::transaction(function () use ($request, $post) {
            $post->update($request->safe()->except('targets'));

            if ($request->has('targets')) {
                $this->replaceTargets($post, $request->input('targets', []));
            }
        });

        return response()->json([
            'message' => __('messages.post.updated'),
            'data' => new PostResource($post->fresh(['type', 'author', 'targets'])),
        ]);
    }

    /** Send a draft into the flow. */
    public function submit(Post $post): JsonResponse
    {
        $this->authorize('update', $post);

        $post->load('type');

        $status = $post->type->requires_approval ? PostStatus::Pending : PostStatus::Published;

        $post->update([
            'status' => $status,
            'published_at' => $status === PostStatus::Published ? now() : null,
        ]);

        // A type that needs no approval publishes immediately, so the
        // audience is notified here rather than in review(). A type that does
        // need approval instead tells the people who can grant it.
        if ($status === PostStatus::Published) {
            PostAudience::notify($post);
        } else {
            PostAudience::notifyApprovers($post);
        }

        return response()->json([
            'message' => $status === PostStatus::Pending
                ? __('messages.post.submitted')
                : __('messages.post.published'),
            'data' => new PostResource($post->fresh(['type', 'targets'])),
        ]);
    }

    /** Approve or reject, recording the decision in the trail. */
    public function review(ReviewPostRequest $request, Post $post): JsonResponse
    {
        $this->authorize('review', $post);

        if ($post->status !== PostStatus::Pending) {
            return response()->json(['message' => __('messages.post.not_pending')], 422);
        }

        $approved = $request->string('decision')->toString() === 'approved';

        DB::transaction(function () use ($request, $post, $approved) {
            $post->approvals()->create([
                'reviewer_id' => $request->user()->id,
                'decision' => $request->input('decision'),
                'note' => $request->input('note'),
                'decided_at' => now(),
            ]);

            $post->update([
                'status' => $approved ? PostStatus::Published : PostStatus::Rejected,
                'published_at' => $approved ? now() : null,
            ]);
        });

        if ($approved) {
            PostAudience::notify($post->fresh('targets'));
        }

        return response()->json([
            'message' => $approved ? __('messages.post.approved') : __('messages.post.rejected'),
            'data' => new PostResource($post->fresh(['type', 'author', 'approvals.reviewer'])),
        ]);
    }

    public function destroy(Post $post): JsonResponse
    {
        $this->authorize('delete', $post);

        $post->delete();

        return response()->json(['message' => __('messages.post.deleted')]);
    }

    private function replaceTargets(Post $post, array $targets): void
    {
        $post->targets()->delete();

        foreach ($targets as $target) {
            $post->targets()->create([
                'scope' => $target['scope'],
                'target_id' => $target['scope'] === TargetScope::School->value
                    ? null
                    : ($target['target_id'] ?? null),
            ]);
        }
    }
}
