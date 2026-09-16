<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Registration of FCM device tokens.
 *
 * The app registers right after sign-in and whenever Firebase rotates the
 * token, and unregisters on sign-out so a shared device stops receiving the
 * previous user's notifications.
 */
class DeviceTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['nullable', Rule::in(['android', 'ios', 'web', 'unknown'])],
        ]);

        // The same token may move between users on a shared device, so the
        // owner is overwritten rather than duplicated.
        DeviceToken::updateOrCreate(
            ['token' => $data['token']],
            [
                'user_id' => $request->user()->id,
                'platform' => $data['platform'] ?? 'unknown',
                'last_used_at' => now(),
            ],
        );

        return response()->json(['message' => __('messages.device_token.registered')]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
        ]);

        DeviceToken::query()
            ->where('user_id', $request->user()->id)
            ->where('token', $data['token'])
            ->delete();

        return response()->json(['message' => __('messages.device_token.removed')]);
    }
}
