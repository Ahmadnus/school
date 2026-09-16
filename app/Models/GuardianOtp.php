<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class GuardianOtp extends Model
{
    protected $fillable = ['phone', 'code_hash', 'attempts', 'expires_at', 'consumed_at'];

    /** Never expose the hash, even by accident. */
    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /** Still usable: not spent, not expired, not brute-forced. */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', self::MAX_ATTEMPTS);
    }

    public const MAX_ATTEMPTS = 5;

    public const TTL_MINUTES = 10;
}
