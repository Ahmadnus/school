<?php

namespace App\Models;

use App\Enums\NotificationApp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchoolNotificationSetting extends Model
{
    use HasFactory;

    /** The keys both settings screens are built from. */
    public const CATALOG = [
        // لا مفتاح للحضور: لم يعد يُسجَّل، ومفتاحٌ في الإعدادات لا يتحكّم
        // بشيء أسوأ من غيابه — يَعِد بما لا يقع.
        ['key' => 'attendance_absence', 'group' => 'attendance'],
        ['key' => 'attendance_summary', 'group' => 'attendance'],
        ['key' => 'attendance_late', 'group' => 'attendance'],
        ['key' => 'excuse_submitted', 'group' => 'attendance'],
        ['key' => 'excuse_reviewed', 'group' => 'attendance'],
        ['key' => 'grade_published', 'group' => 'academic'],
        // كشف الشعبة إلى المشرفين والإدارة — مفتاح مستقل عن علامة الطالب
        // الواحد، حتى تستطيع مدرسة إبقاء إشعار الأهل وإيقاف كشف الكادر.
        ['key' => 'grade_summary', 'group' => 'academic'],
        ['key' => 'report_card_published', 'group' => 'academic'],
        ['key' => 'assessment_created', 'group' => 'academic'],
        ['key' => 'honor_board', 'group' => 'academic'],
        ['key' => 'behavior_record', 'group' => 'academic'],
        ['key' => 'fee_due', 'group' => 'fees'],
        ['key' => 'fee_payment_recorded', 'group' => 'fees'],
        ['key' => 'post_published', 'group' => 'posts'],
        ['key' => 'post_pending_approval', 'group' => 'posts'],
        ['key' => 'message_received', 'group' => 'communication'],
    ];

    protected $fillable = ['school_id', 'app', 'key', 'group', 'is_enabled'];

    protected $attributes = ['is_enabled' => true];

    protected function casts(): array
    {
        return [
            'app' => NotificationApp::class,
            'is_enabled' => 'boolean',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function getNameAttribute(): string
    {
        return __('notification_keys.'.$this->key);
    }

    public function scopeOfSchool(Builder $query, int $schoolId): Builder
    {
        return $query->where('school_id', $schoolId);
    }
}
