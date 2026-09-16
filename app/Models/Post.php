<?php

namespace App\Models;

use App\Enums\PostStatus;
use App\Enums\TargetScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Post extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'post_type_id',
        'author_id',
        'title',
        'body',
        'subject_id',
        'status',
        'requires_confirmation',
        'published_at',
    ];

    protected $attributes = [
        'status' => PostStatus::Draft->value,
    ];

    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'published_at' => 'datetime',
            'requires_confirmation' => 'boolean',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(PostType::class, 'post_type_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(PostTarget::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(PostApproval::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PostReceipt::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'owner', 'owner_type', 'owner_id');
    }

    public function scopeOfSchool(Builder $query, int $schoolId): Builder
    {
        return $query->where('school_id', $schoolId);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', PostStatus::Published);
    }

    /** The approvals inbox. */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', PostStatus::Pending);
    }

    /** Posts aimed at one target — the "targeting scope" filter chip. */
    public function scopeTargeting(Builder $query, TargetScope $scope, ?int $targetId = null): Builder
    {
        return $query->whereHas('targets', fn (Builder $q) => $q
            ->where('scope', $scope)
            ->when($targetId !== null, fn ($sub) => $sub->where('target_id', $targetId)));
    }
}
