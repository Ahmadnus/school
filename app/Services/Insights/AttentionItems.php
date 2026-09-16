<?php

namespace App\Services\Insights;

use App\Enums\AttendanceSessionStatus;
use App\Enums\ConversationStatus;
use App\Enums\ExcuseStatus;
use App\Enums\FeePlanStatus;
use App\Enums\PostStatus;
use App\Enums\UserRole;
use App\Models\AbsenceExcuse;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Conversation;
use App\Models\FeePlanInstallment;
use App\Models\Post;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "What needs attention today?" — one deterministic list built from the
 * school's own records. Each item has a count, a severity and the screen
 * that resolves it. Items with nothing to do are simply absent.
 */
class AttentionItems
{
    /** @return array<int, array<string, mixed>> */
    public static function for(InsightContext $ctx): array
    {
        $items = [];
        $add = function (string $key, int $count, string $severity, string $route, array $params = []) use (&$items) {
            if ($count <= 0) {
                return;
            }
            $items[] = [
                'key' => $key,
                'count' => $count,
                'severity' => $severity,
                'title' => __('insights.attention.'.$key.'.title', ['count' => $count]),
                'body' => __('insights.attention.'.$key.'.body', ['count' => $count]),
                'route' => $route,
                'params' => $params,
            ];
        };

        $schoolId = $ctx->school->id;
        $today = $ctx->today->toDateString();

        // 1. Roll calls still open today.
        if ($ctx->year && $ctx->isSchoolDay()) {
            $submitted = AttendanceSession::query()
                ->whereDate('date', $today)
                ->where('status', AttendanceSessionStatus::Submitted->value)
                ->pluck('section_id');
            $pending = $ctx->sectionsQuery()->whereNotIn('id', $submitted)->count();
            $add('attendance_pending', $pending, 'warning', 'takeAttendance');
        }

        // 2. Students at or over the school's unexcused-absence limit.
        $threshold = (int) ($ctx->school->absence_warning_threshold ?? 0);
        if ($threshold > 0 && $ctx->year) {
            $over = AttendanceRecord::query()
                ->whereHas('section', fn ($s) => $s->where('academic_year_id', $ctx->year->id))
                ->when($ctx->isTeacher(), fn ($q) => $q->whereIn('section_id', $ctx->teacherSectionIds()))
                ->unexcused()
                ->select('student_id', DB::raw('count(*) as c'))
                ->groupBy('student_id')
                ->having('c', '>=', $threshold)
                ->get()
                ->count();
            $add('absence_threshold', $over, 'danger', 'students', ['filter' => 'attention']);
        }

        if ($ctx->isAdministrative()) {
            $add('excuses_pending', AbsenceExcuse::query()->ofSchool($schoolId)
                ->where('status', ExcuseStatus::Pending)->count(), 'info', 'absenceExcuses');

            $add('posts_pending_approval', Post::query()->ofSchool($schoolId)
                ->where('status', PostStatus::Pending)->count(), 'info', 'postApprovals');

            $overdue = FeePlanInstallment::query()
                ->whereHas('plan', fn ($p) => $p->where('status', FeePlanStatus::Active)
                    ->whereHas('student', fn ($s) => $s->ofSchool($schoolId)))
                ->whereDate('due_date', '<', $today)
                ->with('activeAllocations')
                ->get()
                ->filter(fn (FeePlanInstallment $i) => $i->remainingAmount()->isPositive())
                ->count();
            $add('fees_overdue', $overdue, 'warning', 'tuitionFees', ['payment_state' => 'unpaid']);
        }

        // Conversations where a guardian wrote last and no staff reply for a day.
        $awaiting = Conversation::query()
            ->ofSchool($schoolId)
            ->where('type', 'guardians')
            ->whereIn('status', [ConversationStatus::Open->value, ConversationStatus::InProgress->value])
            ->where('last_message_at', '<=', now()->subDay())
            ->when(! $ctx->isAdministrative(), fn ($q) => $q->forUser($ctx->user->id))
            ->whereHas('lastMessage', fn ($m) => $m->whereHas('sender', fn ($u) => $u->where('role', UserRole::Guardian->value)))
            ->count();
        $add('conversations_awaiting_reply', $awaiting, 'warning', 'conversations');

        if (Schema::hasColumn('conversations', 'follow_up_at')) {
            $due = Conversation::query()
                ->ofSchool($schoolId)
                ->whereNotNull('follow_up_at')
                ->whereDate('follow_up_at', '<=', $today)
                ->when(! $ctx->isAdministrative(), fn ($q) => $q->forUser($ctx->user->id))
                ->count();
            $add('follow_ups_due', $due, 'warning', 'conversations', ['filter' => 'follow_up']);
        }

        $add('schedule_conflicts', count(ScheduleConflicts::for($ctx)), 'danger', 'scheduleConflicts');

        if ($ctx->isAdministrative()) {
            $issues = DataHealth::for($ctx);
            $add('data_health_issues', count($issues), 'info', 'dataHealth');

            $setup = SetupProgress::for($ctx);
            $add('setup_incomplete', $setup['percent'] < 100 ? 100 - $setup['percent'] : 0, 'info', 'setupProgress');
        }

        return $items;
    }
}
