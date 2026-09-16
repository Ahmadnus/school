<?php

namespace App\Http\Controllers\Api;

use App\Enums\Status;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    /** Staff list: role tab filter + free-text search, scoped to the caller's school. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->ofSchool($request->user()->school_id)
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->string('role')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($sub) => $sub
                    ->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('specialty', 'like', $term));
            })
            ->orderBy('first_name')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $user = User::create([
            ...$request->safe()->except('password'),
            'school_id' => $request->user()->school_id,
            'password' => $request->filled('password') ? $request->string('password')->toString() : null,
            'status' => $request->input('status', Status::Active->value),
        ]);

        return response()->json([
            'message' => __('messages.user.created'),
            'data' => new UserResource($user),
        ], 201);
    }

    public function show(User $user): UserResource
    {
        $this->authorize('view', $user);

        return new UserResource($user->load('school'));
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $data = $request->safe()->except('password');

        if ($request->filled('password')) {
            $data['password'] = $request->string('password')->toString();
        }

        $user->update($data);

        return response()->json([
            'message' => __('messages.user.updated'),
            'data' => new UserResource($user->fresh()),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        $user->delete();

        return response()->json(['message' => __('messages.user.deleted')]);
    }

    /** Role cards / filter tabs, already translated. */
    public function roles(): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                fn (UserRole $role) => ['value' => $role->value, 'label' => $role->label()],
                UserRole::cases(),
            ),
        ]);
    }
}
