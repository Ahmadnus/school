<?php

namespace App\Services;

use App\Enums\NotificationApp;
use App\Enums\Status;
use App\Enums\TargetScope;
use App\Enums\UserRole;
use App\Models\Post;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns a published post's targets into the people who should hear about it.
 *
 * A post is aimed at a scope — the whole school, a grade, a section, a single
 * student, or a subject — and the audience is the guardians of the students
 * that scope resolves to. Guardians only have accounts once they have signed
 * in to the guardian app, so the fan-out reaches whoever has one and silently
 * skips the rest.
 *
 * Targets are additive: a post carrying both a grade and a section notifies
 * the union of the two, each guardian once.
 */
class PostAudience
{
    /**
     * Notifies everyone a published post concerns.
     *
     * Two audiences, deliberately separate:
     *  - guardians of the students the targets resolve to (the point of the post)
     *  - staff of the school, who need to know what went out — the author aside,
     *    since publishing is already their own action.
     */
    public static function notify(Post $post): void
    {
        $post->loadMissing('targets');

        if ($post->targets->isEmpty()) {
            return;
        }

        self::notifyStaff($post);

        $users = self::guardianUsersFor($post);

        foreach ($users as $user) {
            NotificationGate::notify(
                user: $user,
                key: 'post_published',
                title: $post->title ?? '',
                body: Str::limit(strip_tags((string) $post->body), 120),
                refId: $post->id,
                // Guardians read the guardian switch, not the staff one.
                app: NotificationApp::Guardian,
            );
        }
    }

    /**
     * The guardian accounts behind a post's targets.
     *
     * @return Collection<int, User>
     */
    public static function guardianUsersFor(Post $post): Collection
    {
        $studentIds = self::studentIdsFor($post);

        if ($studentIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereHas('guardian', function ($q) use ($studentIds) {
                $q->whereHas('students', fn ($s) => $s->whereIn('students.id', $studentIds));
            })
            ->get();
    }

    /**
     * Student ids covered by the post's targets, deduplicated.
     *
     * @return Collection<int, int>
     */
    public static function studentIdsFor(Post $post): Collection
    {
        $ids = collect();

        foreach ($post->targets as $target) {
            $query = Student::query()->ofSchool($post->school_id);

            match ($target->scope) {
                // The whole school: every student on the roll.
                TargetScope::School => null,
                TargetScope::Student => $query->whereKey($target->target_id),
                TargetScope::Grade => $query->whereHas(
                    'currentEnrollment.section',
                    fn ($s) => $s->where('grade_id', $target->target_id),
                ),
                TargetScope::Section => $query->whereHas(
                    'currentEnrollment',
                    fn ($e) => $e->where('section_id', $target->target_id),
                ),
                // A subject belongs to a grade, so it reaches the students
                // enrolled in that grade's sections.
                TargetScope::Subject => $query->whereHas(
                    'currentEnrollment.section',
                    fn ($s) => $s->whereIn(
                        'grade_id',
                        Subject::query()->whereKey($target->target_id)->select('grade_id'),
                    ),
                ),
            };

            $ids = $ids->merge($query->pluck('id'));
        }

        return $ids->unique()->values();
    }

    /**
     * Ids of published posts whose targets reach a child of this guardian.
     *
     * The reverse of the fan-out: instead of "who hears about this post", it
     * answers "which posts is this person entitled to see".
     *
     * @return Collection<int, int>
     */
    public static function postIdsForGuardian(User $user): Collection
    {
        $students = Student::query()
            ->whereHas(
                'guardians',
                fn ($g) => $g->where('guardians.user_id', $user->id),
            )
            ->with('currentEnrollment.section')
            ->get();

        if ($students->isEmpty()) {
            return collect();
        }

        $studentIds = $students->pluck('id');
        $sectionIds = $students->pluck('currentEnrollment.section_id')->filter()->unique()->values();
        $gradeIds = $students->pluck('currentEnrollment.section.grade_id')->filter()->unique()->values();

        // One query over post_targets instead of resolving every published
        // post's audience one by one — that loop grew with the school's whole
        // post history on every guardian request.
        return \App\Models\PostTarget::query()
            ->whereIn('post_id', Post::query()->ofSchool($user->school_id)->published()->select('id'))
            ->where(function ($q) use ($studentIds, $sectionIds, $gradeIds) {
                $q->where('scope', TargetScope::School->value)
                    ->orWhere(fn ($s) => $s->where('scope', TargetScope::Student->value)->whereIn('target_id', $studentIds))
                    ->orWhere(fn ($s) => $s->where('scope', TargetScope::Section->value)->whereIn('target_id', $sectionIds))
                    ->orWhere(fn ($s) => $s->where('scope', TargetScope::Grade->value)->whereIn('target_id', $gradeIds))
                    ->orWhere(fn ($s) => $s->where('scope', TargetScope::Subject->value)->whereIn(
                        'target_id',
                        Subject::query()->whereIn('grade_id', $gradeIds)->select('id'),
                    ));
            })
            ->distinct()
            ->pluck('post_id');
    }

    /**
     * Staff notification for a published post.
     *
     * Reads the staff switch, not the guardian one, so a school can silence
     * one audience without the other.
     */
    private static function notifyStaff(Post $post): void
    {
        $staff = User::query()
            ->where('school_id', $post->school_id)
            ->whereIn('role', [
                UserRole::Teacher->value,
                UserRole::Admin->value,
                UserRole::SuperAdmin->value,
            ])
            ->where('status', Status::Active)
            // The author just published it; telling them is noise.
            ->whereKeyNot($post->author_id)
            ->get();

        foreach ($staff as $user) {
            NotificationGate::notify(
                user: $user,
                key: 'post_published',
                title: $post->title ?? '',
                body: Str::limit(strip_tags((string) $post->body), 120),
                refId: $post->id,
                app: NotificationApp::Staff,
            );
        }
    }

    /**
     * Tells the people who can approve that a post is waiting for them.
     *
     * `post_pending_approval` existed in the catalogue but nothing ever raised
     * it, so an approver had no way of knowing a post was queued.
     */
    public static function notifyApprovers(Post $post): void
    {
        $approvers = User::query()
            ->where('school_id', $post->school_id)
            ->whereIn('role', [UserRole::Admin->value, UserRole::SuperAdmin->value])
            ->where('status', Status::Active)
            // A post is never reviewed by its own author (PostPolicy::review).
            ->whereKeyNot($post->author_id)
            ->get();

        foreach ($approvers as $user) {
            NotificationGate::notify(
                user: $user,
                key: 'post_pending_approval',
                title: $post->title ?? '',
                body: Str::limit(strip_tags((string) $post->body), 120),
                refId: $post->id,
                app: NotificationApp::Staff,
            );
        }
    }
}
