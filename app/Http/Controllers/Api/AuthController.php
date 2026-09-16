<?php

namespace App\Http\Controllers\Api;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Login by email or phone; returns a Sanctum token. */
    public function login(LoginRequest $request): JsonResponse
    {
        $login = $request->string('login')->toString();

        $user = User::query()
            ->where('email', $login)
            ->orWhere('phone', $login)
            ->first();

        if (! $user || ! $user->password || ! Hash::check($request->string('password'), $user->password)) {
            throw ValidationException::withMessages([
                'login' => __('messages.auth.invalid_credentials'),
            ]);
        }

        if ($user->status !== Status::Active) {
            throw ValidationException::withMessages([
                'login' => __('messages.auth.inactive_account'),
            ]);
        }

        $token = $user->createToken($request->input('device_name', 'api'))->plainTextToken;

        return response()->json([
            'message' => __('messages.auth.logged_in'),
            'token' => $token,
            'data' => new UserResource($user->load('school')),
        ]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('school'));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => __('messages.auth.logged_out')]);
    }
}
