<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportCardLine extends Model
{
    use HasFactory;

    protected $fillable = ['report_card_id', 'subject_id', 'score', 'grade_label', 'sort_order'];

    protected function casts(): array
    {
        return ['score' => 'decimal:2'];
    }

    public function reportCard(): BelongsTo
    {
        return $this->belongsTo(ReportCard::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
