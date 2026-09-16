<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assessment extends Model
{
    use HasFactory;

    protected $fillable = [
        'subject_id',
        'assessment_type_id',
        'name',
        'held_on',
        'max_score',
        'weight_percent',
    ];

    protected $attributes = [
        'max_score' => 100,
        'weight_percent' => 0,
    ];

    protected function casts(): array
    {
        return [
            'held_on' => DateOnly::class,
            'max_score' => 'decimal:2',
            'weight_percent' => 'decimal:2',
            'published_at' => 'datetime',
        ];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(AssessmentType::class, 'assessment_type_id');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(GradeScore::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /** منشور = رسمي، والأهل يرونه. غير المنشور عمل الأستاذ وحده. */
    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at');
    }

    public function scopeOfSchool(Builder $query, int $schoolId): Builder
    {
        return $query->whereHas('subject', fn (Builder $q) => $q->ofSchool($schoolId));
    }
}
