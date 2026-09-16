<?php

namespace App\Models;

use App\Enums\ConversationStatus;
use App\Enums\ConversationType;
use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id', 'type', 'student_id', 'title', 'status', 'last_message_at',
        'needs_follow_up', 'follow_up_at', 'is_important', 'follow_up_note', 'flagged_by',
    ];

    protected $attributes = [
        'status' => ConversationStatus::Open->value,
    ];

    protected function casts(): array
    {
        return [
            'type' => ConversationType::class,
            'status' => ConversationStatus::class,
            'last_message_at' => 'datetime',
            'needs_follow_up' => 'boolean',
            'is_important' => 'boolean',
            'follow_up_at' => DateOnly::class,
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** The preview line of the thread list. */
    public function lastMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany('sent_at');
    }

    public function flaggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'flagged_by');
    }

    /** Follow-up date reached and the thread still flagged. */
    public function isFollowUpOverdue(): bool
    {
        return $this->needs_follow_up
            && $this->follow_up_at !== null
            && $this->follow_up_at->lt(now()->startOfDay());
    }

    public function participantRecords(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_participants')
            ->withPivot(['is_muted', 'last_read_at'])
            ->withTimestamps();
    }

    public function scopeOfSchool(Builder $query, int $schoolId): Builder
    {
        return $query->where('school_id', $schoolId);
    }

    /** Only threads the user takes part in. */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->whereHas('participantRecords', fn (Builder $q) => $q->where('user_id', $userId));
    }

    public function unreadCountFor(int $userId): int
    {
        $participant = $this->participantRecords()->where('user_id', $userId)->first();

        return $this->messages()
            ->where('sender_id', '!=', $userId)
            ->when($participant?->last_read_at, fn ($q, $at) => $q->where('sent_at', '>', $at))
            ->count();
    }
}
