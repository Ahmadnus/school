<?php

namespace App\Http\Controllers\Api;

use App\Enums\AttendanceStatus;
use App\Enums\ConversationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\StudentResource;
use App\Models\AttendanceRecord;
use App\Models\BehaviorRecord;
use App\Models\Conversation;
use App\Models\FeePlanInstallment;
use App\Models\HonorEntry;
use App\Models\Post;
use App\Models\Student;
use App\Services\AttendanceSummary;
use App\Services\CalendarFeed;
use App\Services\PostAudience;
use App\Services\StudentAttendanceSummary;
use App\Services\StudentSignals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * The guardian digest — "this week, for each of my children", in one call.
 *
 * Everything is scoped to the caller's own children (the same `guardians.user_id`
 * link every guardian endpoint uses) and built from grouped queries over this
 * week's window, so it stays cheap on a phone that opens the app every morning.
 */
class GuardianDigestController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role->isGuardian(), 403, __('messages.unauthorized'));

        $children = Student::query()
            ->ofSchool($user->school_id)
            ->whereHas('guardians', fn ($g) => $g->where('guardians.user_id', $user->id))
            ->with(['currentEnrollment.section.grade', 'school'])
            ->orderBy('first_name')
            ->get();

        if ($children->isEmpty()) {
            return response()->json(['data' => ['week_start' => null, 'children' => []]]);
        }

        $weekStart = now()->startOfWeek(\Carbon\Carbon::SATURDAY)->toDateString();
        $today = now()->toDateString();
        $weekAhead = now()->addDays(7)->toDateString();
        $ids = $children->pluck('id');

        // This week's attendance for all children in one query.
        $weekRecords = AttendanceRecord::query()
            ->whereIn('student_id', $ids)
            ->whereDate('date', '>=', $weekStart)
            ->whereDate('date', '<=', $today)
            ->get(['id', 'student_id', 'date', 'status']);
        $excused = AttendanceSummary::excusedStudentDays($weekRecords);

        // Shared behaviour records in the last 7 days, grouped.
        $behavior = BehaviorRecord::query()
            ->whereIn('student_id', $ids)
            ->sharedWithGuardian()
            ->whereDate('occurred_on', '>=', now()->subDays(7)->toDateString())
            ->selectRaw('student_id, count(*) as c')
            ->groupBy('student_id')
            ->pluck('c', 'student_id');

        // Next unpaid installment per child (soonest due, including overdue).
        $installments = FeePlanInstallment::query()
            ->join('fee_plans', 'fee_plans.id', '=', 'fee_plan_installments.fee_plan_id')
            ->whereIn('fee_plans.student_id', $ids)
            ->where('fee_plans.status', 'active')
            ->whereNotNull('fee_plan_installments.due_date')
            ->orderBy('fee_plan_installments.due_date')
            ->with('activeAllocations')
            ->get(['fee_plan_installments.*', 'fee_plans.student_id as student_id'])
            ->filter(fn ($i) => $i->remainingAmount()->isPositive())
            ->groupBy('student_id');

        $openConversations = Conversation::query()
            ->ofSchool($user->school_id)
            ->forUser($user->id)
            ->whereIn('status', [ConversationStatus::Open->value, ConversationStatus::InProgress->value])
            ->count();

        // Posts of the last 7 days that reach any child; "unread" only when the
        // receipts table exists (another feature), otherwise the plain count.
        $recentPostIds = Post::query()
            ->whereIn('id', PostAudience::postIdsForGuardian($user))
            ->whereDate('published_at', '>=', now()->subDays(7)->toDateString())
            ->pluck('id');
        $unreadPosts = $recentPostIds->count();
        if (Schema::hasTable('post_receipts') && $recentPostIds->isNotEmpty()) {
            $read = \Illuminate\Support\Facades\DB::table('post_receipts')
                ->where('user_id', $user->id)
                ->whereIn('post_id', $recentPostIds)
                ->whereNotNull('read_at')
                ->count();
            $unreadPosts = max(0, $recentPostIds->count() - $read);
        }

        // لوحة الشرف — نافذة ٣٠ يوماً. البطاقة تظهر على الرئيسية ما دام
        // في النافذة تكريم منشور، ثم تنطفئ وحدها. أسماء أبناء هذا الوليّ وحدهم
        // تُذكر — لا نرسل أسماء أبناء الغير إلى هاتف كل أب.
        $honorWindow = HonorEntry::query()
            ->ofSchool($user->school_id)
            ->published()
            ->recent(30);

        $honorMine = (clone $honorWindow)
            ->whereIn('student_id', $ids)
            ->with('student')
            ->orderByDesc('published_at')
            ->get();

        $honorBoard = [
            'days' => 30,
            'total' => (clone $honorWindow)->count(),
            'mine' => $honorMine->map(fn (HonorEntry $e) => [
                'id' => $e->id,
                'student_id' => $e->student_id,
                'student_name' => $e->student?->first_name,
                'category' => $e->category->value,
                'category_label' => $e->category->label(),
                'reason' => $e->reason,
                'published_at' => $e->published_at,
            ])->values(),
        ];

        $data = $children->map(function (Student $child) use (
            $weekRecords, $excused, $behavior, $installments, $today, $weekAhead, $user
        ) {
            $rows = $weekRecords->where('student_id', $child->id);
            $week = ['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0, 'recorded' => $rows->count()];
            foreach ($rows as $r) {
                if ($r->status === AttendanceStatus::Present) {
                    $week['present']++;
                } elseif ($r->status === AttendanceStatus::Late) {
                    $week['late']++;
                } elseif ($excused->has(AttendanceSummary::key($child->id, $r->date->toDateString()))) {
                    $week['excused']++;
                } else {
                    $week['absent']++;
                }
            }

            $yearId = $child->currentEnrollment?->academic_year_id;
            $yearSummary = StudentAttendanceSummary::for($child, function ($q) use ($yearId) {
                if ($yearId) {
                    $q->whereHas('section', fn ($s) => $s->where('academic_year_id', $yearId));
                }
            });

            $next = $installments->get($child->id)?->first();
            $nextInstallment = $next ? [
                'due_date' => $next->due_date->toDateString(),
                'amount' => $next->amount()->toDecimal(),
                'remaining' => $next->remainingAmount()->toDecimal(),
                'overdue' => $next->due_date->toDateString() < $today,
            ] : null;

            $upcoming = collect(CalendarFeed::between(
                schoolId: $child->school_id,
                from: $today,
                to: $weekAhead,
                sectionId: $child->currentEnrollment?->section_id,
                studentId: $child->id,
            ))->reject(fn (array $e) => $e['type'] === 'period')
                ->take(6)
                ->map(fn (array $e) => ['type' => $e['type'], 'date' => $e['date'], 'title' => $e['title']])
                ->values();

            $signals = StudentSignals::forStudent($child, $child->school, includeFees: true);

            $attention = collect($signals['signals'])->pluck('detail')->values();
            if ($nextInstallment && $nextInstallment['overdue']) {
                $attention->push(__('digest.installment_overdue', ['amount' => $nextInstallment['remaining']]));
            }

            return [
                'student' => new StudentResource($child),
                'attendance_week' => $week,
                'attendance_rate_year' => $yearSummary['effective_rate'],
                'next_installment' => $nextInstallment,
                'upcoming' => $upcoming,
                'recent_behavior_shared' => (int) ($behavior[$child->id] ?? 0),
                'signal_level' => $signals['level'],
                'attention_items' => $attention->unique()->values(),
            ];
        })->values();

        return response()->json([
            'data' => [
                'week_start' => $weekStart,
                'unread_posts' => $unreadPosts,
                'open_conversations' => $openConversations,
                'honor_board' => $honorBoard,
                'children' => $data,
            ],
        ]);
    }
}
