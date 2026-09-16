<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class School extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'website',
        'logo_path',
        'currency',
        'phone_country_code',
        'absence_warning_threshold',
        'default_pass_score',
        'default_max_score',
    ];

    protected function casts(): array
    {
        return [
            'absence_warning_threshold' => 'integer',
            'default_pass_score' => 'decimal:2',
            'default_max_score' => 'decimal:2',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
