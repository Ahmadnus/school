<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Http\Resources\UserPreferenceResource;
use App\Models\UserPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The drawer's language, theme and attachment-saving choices. */
class UserPreferenceController extends Controller
{
    public function show(Request $request): UserPreferenceResource
    {
        return new UserPreferenceResource(
            $request->user()->preference ?? new UserPreference(['user_id' => $request->user()->id]),
        );
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['sometimes', 'required', Rule::in(SetLocale::SUPPORTED)],
            'theme' => ['sometimes', 'required', Rule::in(['light', 'dark', 'system'])],
            'attachment_save' => ['sometimes', 'required', Rule::in(['ask', 'always', 'never'])],
        ]);

        $preference = UserPreference::updateOrCreate(
            ['user_id' => $request->user()->id],
            $data,
        );

        return response()->json([
            'message' => __('messages.preference.updated'),
            'data' => new UserPreferenceResource($preference),
        ]);
    }
}
