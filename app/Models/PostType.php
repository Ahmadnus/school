<?php

namespace App\Models;

use App\Enums\PostGroup;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PostType extends Model
{
    use HasFactory;

    /** The 13 fixed types, in picker-grid order. */
    public const CATALOG = [
        ['key' => 'general', 'group' => 'academic'],
        ['key' => 'homework', 'group' => 'academic'],
        ['key' => 'lesson_summary', 'group' => 'academic'],
        ['key' => 'day_summary', 'group' => 'academic'],
        ['key' => 'event', 'group' => 'events'],
        ['key' => 'trip', 'group' => 'events'],
        ['key' => 'meeting', 'group' => 'events'],
        ['key' => 'activity', 'group' => 'events'],
        ['key' => 'reminder', 'group' => 'follow_up'],
        ['key' => 'feedback_request', 'group' => 'follow_up'],
        ['key' => 'poll', 'group' => 'follow_up'],
        ['key' => 'behavior', 'group' => 'follow_up'],
        ['key' => 'warning', 'group' => 'alert'],
    ];

    protected $fillable = ['school_id', 'key', 'group', 'sort_order', 'is_enabled', 'min_role', 'requires_approval'];

    protected $attributes = [
        'is_enabled' => true,
        'requires_approval' => false,
        'min_role' => 'teacher',
    ];

    protected function casts(): array
    {
        return [
            'group' => PostGroup::class,
            'is_enabled' => 'boolean',
            'requires_approval' => 'boolean',
            'min_role' => UserRole::class,
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function getNameAttribute(): string
    {
        return __('post_types.'.$this->key);
    }

    /**
     * Permissions cascade upwards: anything a teacher may do stays available to
     * supervisors and the general supervisor.
     */
    public function allows(User $user): bool
    {
        if (! $this->is_enabled) {
            return false;
        }

        return $this->rank($user->role) >= $this->rank($this->min_role);
    }

    /** The explanatory line under the permission chips. */
    public function allowedRoles(): array
    {
        return array_values(array_filter(
            [UserRole::Teacher, UserRole::Admin, UserRole::SuperAdmin],
            fn (UserRole $role) => $this->rank($role) >= $this->rank($this->min_role),
        ));
    }

    private function rank(UserRole $role): int
    {
        return match ($role) {
            UserRole::Teacher => 1,
            UserRole::Admin => 2,
            UserRole::SuperAdmin => 3,
            // Neither drivers nor guardians author posts; they only read.
            UserRole::Driver, UserRole::Guardian => 0,
        };
    }

    public function scopeOfSchool(Builder $query, int $schoolId): Builder
    {
        return $query->where('school_id', $schoolId);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }
}
