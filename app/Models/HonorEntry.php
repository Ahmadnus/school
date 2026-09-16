<?php

namespace App\Models;

use App\Enums\HonorCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HonorEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'student_id',
        'academic_year_id',
        'subject_id',
        'awarded_by',
        'category',
        'reason',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'category' => HonorCategory::class,
            'published_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function awarder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'awarded_by');
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function scopeOfSchool(Builder $query, int $schoolId): Builder
    {
        return $query->where('school_id', $schoolId);
    }

    /** ما يراه الأهالي: المنشور فقط، لا المسوّدات. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at');
    }

    /**
     * نافذة الواجهة الرئيسية للأهالي: التكريمات الحديثة وحدها.
     *
     * البطاقة تختفي من الرئيسية بمرور الوقت لا بحذف التكريم — فاللوحة الكاملة
     * تبقى سجلاً دائماً. وللإدارة أن تشيل تكريماً بعينه مبكراً بحذفه.
     */
    public function scopeRecent(Builder $query, int $days = 30): Builder
    {
        return $query->where('published_at', '>=', now()->subDays($days));
    }
}
