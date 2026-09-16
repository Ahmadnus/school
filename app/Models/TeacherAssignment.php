<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherAssignment extends Model
{
    use HasFactory;

    protected $fillable = ['staff_id', 'subject_id', 'section_id'];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function scopeOfSchool(Builder $query, int $schoolId): Builder
    {
        return $query->whereHas('teacher', fn (Builder $q) => $q->where('school_id', $schoolId));
    }
}
