<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rubric extends Model
{
    use HasFactory;

    protected $fillable = ['school_id', 'name', 'is_default'];

    protected $attributes = [
        'is_default' => false,
    ];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function levels(): HasMany
    {
        return $this->hasMany(RubricLevel::class)->orderBy('sort_order');
    }

    public function scopeOfSchool(Builder $query, int $schoolId): Builder
    {
        return $query->where('school_id', $schoolId);
    }
}
