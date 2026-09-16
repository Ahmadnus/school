<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\Insights\AttentionItems;
use App\Services\Insights\ClassHealth;
use App\Services\Insights\DataHealth;
use App\Services\Insights\InsightContext;
use App\Services\Insights\ScheduleConflicts;
use App\Services\Insights\SetupProgress;
use App\Services\Insights\StaffWorkload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Operational insights — read-only aggregations over the school's own data.
 *
 * Administrators see everything; teachers get the attention list, class
 * health and schedule conflicts narrowed to their own sections; guardians
 * and drivers have no business here (403).
 */
class InsightsController extends Controller
{
    public function attention(Request $request): JsonResponse
    {
        $ctx = $this->context($request, teachers: true);

        return response()->json(['data' => [
            'generated_at' => now(),
            'items' => AttentionItems::for($ctx),
        ]]);
    }

    public function classHealth(Request $request): JsonResponse
    {
        $ctx = $this->context($request, teachers: true);

        return response()->json(['data' => ClassHealth::for(
            $ctx,
            $request->filled('academic_year_id') ? $request->integer('academic_year_id') : null,
        )]);
    }

    public function staffWorkload(Request $request): JsonResponse
    {
        $ctx = $this->context($request);

        return response()->json(['data' => StaffWorkload::for($ctx)]);
    }

    public function scheduleConflicts(Request $request): JsonResponse
    {
        $ctx = $this->context($request, teachers: true);

        return response()->json(['data' => ScheduleConflicts::for($ctx)]);
    }

    public function dataHealth(Request $request): JsonResponse
    {
        $ctx = $this->context($request);

        return response()->json(['data' => ['issues' => DataHealth::for($ctx)]]);
    }

    public function setupProgress(Request $request): JsonResponse
    {
        $ctx = $this->context($request);

        return response()->json(['data' => SetupProgress::for($ctx)]);
    }

    private function context(Request $request, bool $teachers = false): InsightContext
    {
        $user = $request->user();
        $allowed = $user->role->isAdministrative()
            || ($teachers && $user->role === UserRole::Teacher);

        abort_unless($allowed, 403, __('messages.unauthorized'));

        return new InsightContext($user);
    }
}
