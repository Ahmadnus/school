<?php

namespace App\Http\Controllers\Api;

use App\Enums\NotificationApp;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Http\Resources\NotificationSettingResource;
use App\Models\Notification;
use App\Models\SchoolNotificationSetting;
use App\Models\UserNotificationSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $notifications = $request->user()->notifications()
            ->when($request->boolean('unread_only'), fn ($q) => $q->unread())
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return NotificationResource::collection($notifications);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['count' => $request->user()->notifications()->unread()->count()],
        ]);
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);

        $notification->update(['is_read' => true]);

        return response()->json(['message' => __('messages.notification.read')]);
    }

    /** The ⋮ menu of the notifications screen. */
    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->notifications()->unread()->update(['is_read' => true]);

        return response()->json(['message' => __('messages.notification.all_read')]);
    }

    /**
     * The user's own switches (the drawer's notification settings). Keys with
     * no row yet default to on.
     */
    public function mySettings(Request $request): AnonymousResourceCollection
    {
        $existing = $request->user()->notificationSettings()->get()->keyBy('key');

        $settings = collect(SchoolNotificationSetting::CATALOG)->map(
            fn (array $entry) => $existing->get($entry['key']) ?? new UserNotificationSetting([
                'user_id' => $request->user()->id,
                'key' => $entry['key'],
                'is_enabled' => true,
            ])
        )->map(function (UserNotificationSetting $setting, $index) {
            $setting->group ??= SchoolNotificationSetting::CATALOG[$index]['group'];

            return $setting;
        });

        return NotificationSettingResource::collection($settings);
    }

    public function updateMySetting(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:100'],
            'is_enabled' => ['required', 'boolean'],
        ]);

        UserNotificationSetting::updateOrCreate(
            ['user_id' => $request->user()->id, 'key' => $data['key']],
            ['is_enabled' => $data['is_enabled']],
        );

        return response()->json(['message' => __('messages.notification.setting_updated')]);
    }

    /** The school-level screen, with its staff / guardians tabs. */
    public function schoolSettings(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SchoolNotificationSetting::class);

        $settings = SchoolNotificationSetting::query()
            ->ofSchool($request->user()->school_id)
            ->when($request->filled('app'), fn ($q) => $q->where('app', $request->string('app')))
            ->orderBy('group')
            ->get();

        return NotificationSettingResource::collection($settings);
    }

    public function updateSchoolSetting(Request $request, SchoolNotificationSetting $setting): JsonResponse
    {
        $this->authorize('update', $setting);

        $data = $request->validate([
            'is_enabled' => ['required', 'boolean'],
        ]);

        $setting->update($data);

        return response()->json([
            'message' => __('messages.notification.setting_updated'),
            'data' => new NotificationSettingResource($setting->fresh()),
        ]);
    }

    /** Both switches must allow it before anything is delivered (decision 8-a). */
    public function apps(): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                fn (NotificationApp $app) => ['value' => $app->value, 'label' => $app->label()],
                NotificationApp::cases(),
            ),
        ]);
    }
}
