<?php

namespace App\Models;

use App\Enums\GuardianRelation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentGuardian extends Model
{
    use HasFactory;

    protected $table = 'student_guardians';

    protected $fillable = ['student_id', 'guardian_id', 'relation', 'is_primary'];

    protected $attributes = [
        'is_primary' => false,
    ];

    protected function casts(): array
    {
        return [
            'relation' => GuardianRelation::class,
            'is_primary' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // One primary contact per student (decision 14-a). The partial unique index
        // covers sqlite/pgsql; this keeps the rule on every driver by demoting the
        // previous primary instead of failing.
        $demoteOthers = function (self $link) {
            if (! $link->is_primary) {
                return;
            }

            static::query()
                ->where('student_id', $link->student_id)
                ->when($link->exists, fn ($q) => $q->whereKeyNot($link->getKey()))
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
        };

        static::creating($demoteOthers);
        static::updating($demoteOthers);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }
}
