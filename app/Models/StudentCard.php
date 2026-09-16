<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentCard extends Model
{
    use HasFactory;

    protected $fillable = ['student_id', 'nfc_uid', 'issued_at', 'revoked_at', 'is_active'];

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'revoked_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // One active card per student: issuing a new card revokes the old one.
        static::creating(function (self $card) {
            $card->issued_at ??= now();

            if ($card->is_active) {
                static::query()
                    ->where('student_id', $card->student_id)
                    ->where('is_active', true)
                    ->update(['is_active' => false, 'revoked_at' => now()]);
            }
        });
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function revoke(): void
    {
        $this->forceFill(['is_active' => false, 'revoked_at' => now()])->save();
    }
}
