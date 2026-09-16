<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\ReportCardStatus;
use App\Models\AbsenceExcuse;
use App\Models\AttendanceRecord;
use App\Models\BehaviorRecord;
use App\Models\FeePayment;
use App\Models\ReportCard;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentGuardian;
use App\Models\StudentNote;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The student's journey as one chronological stream.
 *
 * Each source is queried once for the requested window and merged in PHP,
 * newest first. What the caller may *not* see never enters the stream:
 * internal notes need `viewNotes`, payments need `viewFees`, and a guardian
 * gets only behaviour records the school chose to share.
 */
class StudentTimeline
{
    public const TYPES = ['enrollment', 'attendance', 'excuse', 'behavior', 'note', 'payment', 'report_card', 'guardian'];

    /**
     * @param  array<int, string>  $types
     * @return Collection<int, array{type:string, date:string, title:string, detail:?string, ref_id:?int, tone:string}>
     */
    public static function for(Student $student, User $viewer, string $from, string $to, array $types = []): Collection
    {
        $allowed = collect(self::TYPES)
            ->when($types !== [], fn ($c) => $c->intersect($types))
            ->reject(fn (string $t) => ($t === 'note' && ! $viewer->can('viewNotes', $student))
                || ($t === 'payment' && ! $viewer->can('viewFees', $student)))
            ->values();

        $events = collect();

        if ($allowed->contains('enrollment')) {
            $rows = StudentEnrollment::query()
                ->where('student_id', $student->id)
                ->whereDate('enrolled_at', '>=', $from)->whereDate('enrolled_at', '<=', $to)
                ->with(['section.grade', 'academicYear'])
                ->get();
            foreach ($rows as $e) {
                $events->push(self::event('enrollment', $e->enrolled_at?->toDateString() ?? $e->created_at->toDateString(),
                    __('timeline.enrollment'),
                    trim(($e->section?->grade?->name ?? '').' - '.($e->section?->name ?? ''), ' -').' · '.($e->academicYear?->name ?? ''),
                    $e->id, 'primary'));
            }
        }

        if ($allowed->contains('attendance')) {
            $rows = AttendanceRecord::query()
                ->where('student_id', $student->id)
                ->whereIn('status', [AttendanceStatus::Absent->value, AttendanceStatus::Late->value])
                ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
                ->get(['id', 'student_id', 'date', 'status']);
            $excused = AttendanceSummary::excusedStudentDays($rows);
            foreach ($rows as $r) {
                $isExcused = $excused->has(AttendanceSummary::key($r->student_id, $r->date->toDateString()));
                $events->push(self::event('attendance', $r->date->toDateString(),
                    $isExcused ? __('timeline.attendance_excused') : $r->status->label(),
                    null, $r->id,
                    $r->status === AttendanceStatus::Late ? 'warning' : ($isExcused ? 'accent' : 'danger')));
            }
        }

        if ($allowed->contains('excuse')) {
            $rows = AbsenceExcuse::query()->where('student_id', $student->id)
                ->whereDate('start_date', '>=', $from)->whereDate('start_date', '<=', $to)->get();
            foreach ($rows as $x) {
                $events->push(self::event('excuse', $x->start_date->toDateString(),
                    __('timeline.excuse', ['status' => $x->status->label()]), $x->reason, $x->id,
                    match ($x->status->value) { 'accepted' => 'primary', 'rejected' => 'danger', default => 'warning' }));
            }
        }

        if ($allowed->contains('behavior')) {
            $rows = BehaviorRecord::query()->where('student_id', $student->id)
                ->when($viewer->role->isGuardian(), fn ($q) => $q->sharedWithGuardian())
                ->whereDate('occurred_on', '>=', $from)->whereDate('occurred_on', '<=', $to)->get();
            foreach ($rows as $b) {
                $events->push(self::event('behavior', $b->occurred_on->toDateString(),
                    $b->type->label().' — '.$b->title, $b->description, $b->id,
                    $b->type->value === 'positive' ? 'primary' : 'warning'));
            }
        }

        if ($allowed->contains('note')) {
            $rows = StudentNote::query()->where('student_id', $student->id)->with('author')
                ->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)->get();
            foreach ($rows as $n) {
                $events->push(self::event('note', $n->created_at->toDateString(),
                    __('timeline.note', ['name' => $n->author?->full_name ?? '']), $n->body, $n->id, 'neutral'));
            }
        }

        if ($allowed->contains('payment')) {
            $rows = FeePayment::query()
                ->whereIn('fee_plan_id', $student->feePlans()->select('id'))
                ->whereDate('paid_on', '>=', $from)->whereDate('paid_on', '<=', $to)->active()->get();
            foreach ($rows as $p) {
                $events->push(self::event('payment', $p->paid_on->toDateString(),
                    __('timeline.payment', ['amount' => $p->amount()->toDecimal()]),
                    $p->description, $p->fee_plan_id, 'primary'));
            }
        }

        if ($allowed->contains('report_card')) {
            $rows = ReportCard::query()->where('student_id', $student->id)
                ->where('status', ReportCardStatus::Published)
                ->whereDate('published_at', '>=', $from)->whereDate('published_at', '<=', $to)
                ->with('term')->get();
            foreach ($rows as $c) {
                $events->push(self::event('report_card', $c->published_at->toDateString(),
                    __('timeline.report_card', ['term' => $c->term?->name ?? '']),
                    $c->average === null ? null : __('timeline.average', ['value' => $c->average]), $c->id, 'primary'));
            }
        }

        if ($allowed->contains('guardian')) {
            $rows = StudentGuardian::query()->where('student_id', $student->id)->with('guardian')
                ->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)->get();
            foreach ($rows as $g) {
                $events->push(self::event('guardian', $g->created_at->toDateString(),
                    __('timeline.guardian_linked', ['name' => $g->guardian?->name ?? '']),
                    __('guardian_relations.'.$g->relation->value), $g->guardian_id, 'accent'));
            }
        }

        return $events->sortByDesc('date')->values();
    }

    private static function event(string $type, string $date, string $title, ?string $detail, ?int $refId, string $tone): array
    {
        return compact('type', 'date', 'title', 'detail') + ['ref_id' => $refId, 'tone' => $tone];
    }
}
