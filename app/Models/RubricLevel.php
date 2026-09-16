<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RubricLevel extends Model
{
    use HasFactory;

    protected $fillable = ['rubric_id', 'name', 'value', 'sort_order'];

    protected function casts(): array
    {
        return ['value' => 'decimal:2'];
    }

    public function rubric(): BelongsTo
    {
        return $this->belongsTo(Rubric::class);
    }
}
