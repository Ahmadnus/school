<?php

namespace App\Services\Insights;

use App\Models\ScheduleSlot;
use Illuminate\Support\Collection;

/**
 * Overlapping timetable slots of the current term: the same teacher, room or
 * section booked twice at once on the same day. Read-only — nothing is moved
 * automatically; the administrator fixes the slot they choose.
 */
class ScheduleConflicts
{
    /** @return array<int, array<string, mixed>> */
    public static function for(InsightContext $ctx): array
    {
        if (! $ctx->term) {
            return [];
        }

        $slots = ScheduleSlot::query()
            ->where('term_id', $ctx->term->id)
            ->whereHas('section.grade', fn ($g) => $g->where('school_id', $ctx->school->id))
            ->with(['section.grade', 'subject', 'teacher'])
            ->get();

        if ($ctx->isTeacher()) {
            $mine = $slots->filter(fn (ScheduleSlot $s) => $s->staff_id === $ctx->user->id);
            // Room/section conflicts involving the teacher's own slots only.
            $slots = $slots->filter(fn (ScheduleSlot $s) => $mine->contains(
                fn (ScheduleSlot $m) => $m->id === $s->id
                    || ($s->day_of_week === $m->day_of_week && ($s->room === $m->room && $s->room !== null || $s->section_id === $m->section_id)),
            ));
        }

        $conflicts = [];
        $conflicts = array_merge($conflicts, self::detect($slots->whereNotNull('staff_id'), 'staff_id', 'teacher', $ctx));
        $conflicts = array_merge($conflicts, self::detect($slots->whereNotNull('room')->where('room', '!=', ''), 'room', 'room', $ctx));
        $conflicts = array_merge($conflicts, self::detect($slots, 'section_id', 'section', $ctx));

        if ($ctx->isTeacher()) {
            $conflicts = array_values(array_filter(
                $conflicts,
                fn (array $c) => collect($c['slots'])->intersect($slots->where('staff_id', $ctx->user->id)->pluck('id'))->isNotEmpty(),
            ));
        }

        return $conflicts;
    }

    /**
     * @param  Collection<int, ScheduleSlot>  $slots
     * @return array<int, array<string, mixed>>
     */
    private static function detect(Collection $slots, string $key, string $type, InsightContext $ctx): array
    {
        $out = [];

        foreach ($slots->groupBy(fn (ScheduleSlot $s) => $s->{$key}.'|'.$s->day_of_week->value) as $group) {
            $sorted = $group->sortBy('starts_at')->values();

            for ($i = 0; $i < $sorted->count(); $i++) {
                for ($j = $i + 1; $j < $sorted->count(); $j++) {
                    $a = $sorted[$i];
                    $b = $sorted[$j];

                    if ($b->starts_at >= $a->ends_at) {
                        break;
                    }

                    $out[] = [
                        'type' => $type,
                        'day' => $a->day_of_week->value,
                        'day_label' => $a->day_of_week->label(),
                        'start_time' => max($a->starts_at, $b->starts_at),
                        'end_time' => min($a->ends_at, $b->ends_at),
                        'slots' => [$a->id, $b->id],
                        'labels' => [self::label($a), self::label($b)],
                        'subject' => match ($type) {
                            'teacher' => $a->teacher?->full_name,
                            'room' => $a->room,
                            default => trim(($a->section?->grade?->name ?? '').' - '.($a->section?->name ?? ''), ' -'),
                        },
                    ];
                }
            }
        }

        return $out;
    }

    private static function label(ScheduleSlot $s): string
    {
        $section = trim(($s->section?->grade?->name ?? '').' - '.($s->section?->name ?? ''), ' -');
        $parts = array_filter([$s->subject?->name, $section, $s->teacher?->full_name, $s->room]);

        return implode(' · ', $parts).' ('.substr((string) $s->starts_at, 0, 5).'–'.substr((string) $s->ends_at, 0, 5).')';
    }
}
