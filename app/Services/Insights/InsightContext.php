<?php

namespace App\Services\Insights;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\Holiday;
use App\Models\School;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What every insight needs to know about the caller's school: the current
 * year and term, whether today is a school day, and — for teachers — which
 * sections are "theirs". Computed once per request and shared.
 */
class InsightContext
{
    public readonly School $school;

    public readonly ?AcademicYear $year;

    public readonly ?Term $term;

    public readonly Carbon $today;

    private ?Collection $teacherSectionIds = null;

    public function __construct(public readonly User $user)
    {
        $this->school = $user->school;
        $this->year = AcademicYear::currentFor($user->school_id);
        $this->term = $this->year
            ? Term::query()->where('academic_year_id', $this->year->id)->current()->first()
            : null;
        $this->today = now()->startOfDay();
    }

    public function isAdministrative(): bool
    {
        return $this->user->role->isAdministrative();
    }

    public function isTeacher(): bool
    {
        return $this->user->role === UserRole::Teacher;
    }

    /** Weekend (config kader.weekend, Carbon day numbers) or a holiday → no roll call expected. */
    public function isSchoolDay(?Carbon $date = null): bool
    {
        $date ??= $this->today;

        if (in_array($date->dayOfWeek, config('kader.weekend', [5, 6]), true)) {
            return false;
        }

        return ! Holiday::query()
            ->ofSchool($this->school->id)
            ->where('start_date', '<=', $date->toDateString())
            ->where('end_date', '>=', $date->toDateString())
            ->exists();
    }

    /** Sections of the current year, narrowed to the teacher's own when the caller teaches. */
    public function sectionsQuery()
    {
        $query = Section::query()
            ->whereHas('grade', fn ($g) => $g->where('school_id', $this->school->id))
            ->when($this->year, fn ($q) => $q->where('academic_year_id', $this->year->id));

        if ($this->isTeacher()) {
            $query->whereIn('id', $this->teacherSectionIds());
        }

        return $query;
    }

    /** @return Collection<int, int> */
    public function teacherSectionIds(): Collection
    {
        return $this->teacherSectionIds ??= $this->user->teacherAssignments()->pluck('section_id')
            ->merge($this->user->supervisedSections()->pluck('sections.id'))
            ->unique()
            ->values();
    }
}
