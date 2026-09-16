<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Internal staff note about a student — never shown to guardians. */
class StudentNote extends Model
{
    use HasFactory;

    protected $fillable = ['student_id', 'author_id', 'body'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function scopeOfSchool(Builder $query, int $schoolId): Builder
    {
        return $query->whereHas('student', fn (Builder $q) => $q->ofSchool($schoolId));
    }
}
