<?php

namespace App\Models;

use App\Enums\Status;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Guardian extends Model
{
    use HasFactory;

    protected $fillable = ['school_id', 'user_id', 'name', 'phone', 'email', 'status'];

    protected $attributes = [
        'status' => Status::Active->value,
    ];

    protected function casts(): array
    {
        return ['status' => Status::class];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(StudentGuardian::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'student_guardians')
            ->withPivot(['relation', 'is_primary'])
            ->withTimestamps();
    }

    public function scopeOfSchool(Builder $query, int $schoolId): Builder
    {
        return $query->where('school_id', $schoolId);
    }

    /** The sign-in account, created on first successful OTP verification. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
