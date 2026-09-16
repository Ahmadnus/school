<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradeScore extends Model
{
    use HasFactory;

    protected $table = 'grades_scores';

    protected $fillable = [
        'assessment_id',
        'student_id',
        'score',
        'rubric_level_id',
        'entered_at',
        'entered_by',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'entered_at' => 'datetime',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function rubricLevel(): BelongsTo
    {
        return $this->belongsTo(RubricLevel::class);
    }
}
