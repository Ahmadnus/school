<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\ConversationStatus;
use App\Enums\ConversationType;
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
    /**
     * يرفق `unread_count` لهذا المستخدم في استعلام القائمة نفسه.
     *
     * عتبة القراءة تختلف لكل محادثة، فلا يكفي تجميع واحد — ومن هنا جاءت
     * الجملة الفرعية المترابطة. بدونها كان كل صفّ يكلّف استعلام عدّ مستقلّاً.
     *
     * `COALESCE` يجعل من لم يقرأ قطّ يرى كل الرسائل غير مقروءة، وهو مدعوم
     * في MySQL وSQLite معاً (النشر والاختبارات).
     */
    public function scopeWithUnreadCountFor(Builder $query, int $userId): Builder
    {
        return $query->withCount(['messages as unread_count' => fn (Builder $q) => $q
            ->where('sender_id', '!=', $userId)
            ->whereRaw(
                'messages.sent_at > COALESCE((select last_read_at from conversation_participants'
                .' where conversation_participants.conversation_id = messages.conversation_id'
                ." and conversation_participants.user_id = ?), '1970-01-01 00:00:00')",
                [$userId],
            )]);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->whereHas('participantRecords', fn (Builder $q) => $q->where('user_id', $userId));
    }

    /**
     * عدد غير المقروء لهذا المستخدم.
     *
     * يقرأ المشارك من العلاقة **المحمّلة مسبقاً** متى وُجدت: القائمة تحمّل
     * `participantRecords` لكل المحادثات باستعلام واحد، ثم كان هذا السطر يسأل
     * عنها مرّة أخرى لكل محادثة — استعلامان لكل صفّ في قائمة تُفتح كل دقيقة.
     */
    public function unreadCountFor(int $userId): int
    {
        // محمّل مع القائمة؟ فلا داعي لسؤال قاعدة البيانات مرّة أخرى.
        if ($this->unread_count !== null) {
            return (int) $this->unread_count;
        }

        $participant = $this->relationLoaded('participantRecords')
            ? $this->participantRecords->firstWhere('user_id', $userId)
            : $this->participantRecords()->where('user_id', $userId)->first();

        return $this->messages()
            ->where('sender_id', '!=', $userId)
            ->when($participant?->last_read_at, fn ($q, $at) => $q->where('sent_at', '>', $at))
            ->count();
    }
}
