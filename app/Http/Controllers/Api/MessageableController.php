<?php

namespace App\Http\Controllers\Api;

use App\Enums\Status;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * People the current user may start a conversation with.
 *
 * `GET /users` is closed to guardians on purpose — it exposes the whole staff
 * directory with phones and roles. A guardian still needs to pick a teacher to
 * write to, so this endpoint returns the minimum required for that: id, name
 * and role label, and active staff only.
 *
 * Staff calling it get the same list, so the picker has one source.
 */
class MessageableController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $staff = User::query()
            ->where('school_id', $request->user()->school_id)
            ->whereIn('role', [
                UserRole::Teacher->value,
                UserRole::Admin->value,
                UserRole::SuperAdmin->value,
            ])
            ->where('status', Status::Active)
            // Writing to yourself is never useful.
            ->whereKeyNot($request->user()->id)
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($sub) => $sub
                    ->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term));
            })
            ->orderBy('first_name')
            ->get();

        return response()->json([
            'data' => $staff->map(fn (User $user) => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'role' => $user->role->value,
                'role_label' => $user->role->label(),
                'specialty' => $user->specialty,
            ])->all(),
        ]);
    }
}
