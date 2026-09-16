<?php

namespace App\Models;

use App\Enums\Status;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'school_id',
        'first_name',
        'last_name',
        'phone',
        'email',
        'password',
        'role',
        'specialty',
        'status',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $attributes = [
        'role' => UserRole::Teacher->value,
        'status' => Status::Active->value,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => Status::class,
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** Sections this staff member supervises (decision 7-a). */
    public function supervisedSections(): BelongsToMany
    {
        return $this->belongsToMany(Section::class, 'supervisor_scopes', 'staff_id', 'section_id')
            ->withTimestamps();
    }

    /** Subject+section triples this teacher is assigned to (decision 15-b). */
    public function teacherAssignments(): HasMany
    {
        return $this->hasMany(TeacherAssignment::class, 'staff_id');
    }

    public function taughtSubjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'teacher_assignments', 'staff_id', 'subject_id')
            ->withPivot('section_id')
            ->withTimestamps();
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function notificationSettings(): HasMany
    {
        return $this->hasMany(UserNotificationSetting::class);
    }

    public function preference(): HasOne
    {
        return $this->hasOne(UserPreference::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function hasRole(UserRole ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function scopeOfSchool(Builder $query, int $schoolId): Builder
    {
        return $query->where('school_id', $schoolId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', Status::Active);
    }

    /** The guardian record this account was created from, if any. */
    public function guardian(): HasOne
    {
        return $this->hasOne(Guardian::class);
    }
}
