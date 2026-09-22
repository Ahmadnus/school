<?php

namespace App\Services\Insights;

use App\Enums\AttendanceSessionStatus;
use App\Enums\BehaviorStatus;
use App\Enums\ConversationStatus;
use App\Enums\PostStatus;
use App\Enums\Status;
use App\Enums\UserRole;
use App\Models\AttendanceSession;
use App\Models\BehaviorRecord;
use App\Models\Conversation;
use App\Models\Post;
use App\Models\ScheduleSlot;
use App\Models\StudentEnrollment;
use App\Models\TeacherAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Where the team's load sits: sections, subjects, weekly periods, students,
 * today's pending roll calls, open behaviour records, conversations waiting
 * for a staff reply and posts awaiting approval — per active staff member.
 * Operational signals only, presented neutrally.
 */
class StaffWorkload
{
    /** @return array<int, array<string, mixed>> */
    public static function for(InsightContext $ctx): array
    {
        $schoolId = $ctx->school->id;

        $staff = User::query()
            ->where('school_id', $schoolId)
            ->whereIn('role', [UserRole::Teacher->value, UserRole::Admin->value, UserRole::SuperAdmin->value])
            ->where('status', Status::Active)
            ->orderBy('first_name')
            ->get();

        if ($staff->isEmpty()) {
            return [];
        }

        $ids = $staff->pluck('id');

        $assignments = TeacherAssignment::query()
            ->whereIn('staff_id', $ids)
            ->when($ctx->year, fn ($q) => $q->whereHas('section', fn ($s) => $s->where('academic_year_id', $ctx->year->id)))
            ->get(['staff_id', 'section_id', 'subject_id'])
            ->groupBy('staff_id');

        $allSectionIds = $assignments->flatten()->pluck('section_id')->unique();
        $studentsPerSection = StudentEnrollment::query()
            ->whereIn('section_id', $allSectionIds)
            ->where('status', 'active')
            ->select('section_id', DB::raw('count(*) as c'))
            ->groupBy('section_id')
            ->pluck('c', 'section_id');

        $periods = $ctx->term
            ? ScheduleSlot::query()->where('term_id', $ctx->term->id)->whereIn('staff_id', $ids)
                ->select('staff_id', DB::raw('count(*) as c'))->groupBy('staff_id')->pluck('c', 'staff_id')
            : collect();

        $submittedToday = $ctx->isSchoolDay()
            ? AttendanceSession::query()->whereDate('date', $ctx->today->toDateString())
                ->where('status', AttendanceSessionStatus::Submitted->value)->pluck('section_id')
            : null;

        $openBehavior = BehaviorRecord::query()
            ->whereIn('recorded_by', $ids)->where('status', BehaviorStatus::Open->value)
            ->select('recorded_by', DB::raw('count(*) as c'))->groupBy('recorded_by')->pluck('c', 'recorded_by');

        $pendingPosts = Post::query()
            ->whereIn('author_id', $ids)->where('status', PostStatus::Pending)
            ->select('author_id', DB::raw('count(*) as c'))->groupBy('author_id')->pluck('c', 'author_id');

        // Conversations awaiting a staff reply, attributed to each staff participant.
        $awaiting = Conversation::query()
            ->ofSchool($schoolId)
            ->whereIn('status', [ConversationStatus::Open->value, ConversationStatus::InProgress->value])
            ->whereHas('lastMessage', fn ($m) => $m->whereHas('sender', fn ($u) => $u->where('role', UserRole::Guardian->value)))
            ->with('participantRecords:conversation_id,user_id')
            ->get()
            ->flatMap(fn (Conversation $c) => $c->participantRecords->pluck('user_id'))
            ->countBy();

        $rows = [];
        foreach ($staff as $user) {
            $mine = $assignments->get($user->id, collect());
            $sectionIds = $mine->pluck('section_id')->unique();
            $pending = $submittedToday === null ? 0 : $sectionIds->diff($submittedToday)->count();

            $rows[] = [
                'user_id' => $user->id,
                'full_name' => $user->full_name,
                'role' => $user->role->value,
                'role_label' => $user->role->label(),
                'specialty' => $user->specialty,
                'sections_count' => $sectionIds->count(),
                'subjects_count' => $mine->pluck('subject_id')->unique()->count(),
                'periods_per_week' => (int) ($periods[$user->id] ?? 0),
                'students_count' => (int) $sectionIds->sum(fn ($id) => (int) ($studentsPerSection[$id] ?? 0)),
                'pending_attendance_today' => $pending,
                'open_behavior_recorded' => (int) ($openBehavior[$user->id] ?? 0),
                'awaiting_reply_conversations' => (int) ($awaiting[$user->id] ?? 0),
                'posts_pending' => (int) ($pendingPosts[$user->id] ?? 0),
            ];
        }

        return $rows;
    }
}
