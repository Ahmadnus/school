<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Post\UpdatePostTypeRequest;
use App\Http\Resources\PostTypeResource;
use App\Models\PostType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PostTypeController extends Controller
{
    /** The 13-cell picker grid and the permissions screen read from here. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $types = PostType::query()
            ->ofSchool($request->user()->school_id)
            ->when($request->boolean('enabled_only'), fn ($q) => $q->enabled())
            ->withCount('posts')
            ->orderBy('sort_order')
            ->get();

        return PostTypeResource::collection($types);
    }

    public function update(UpdatePostTypeRequest $request, PostType $postType): JsonResponse
    {
        $this->authorize('update', $postType);

        $postType->update($request->validated());

        return response()->json([
            'message' => __('messages.post_type.updated'),
            'data' => new PostTypeResource($postType->fresh()),
        ]);
    }
}
