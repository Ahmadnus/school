<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotificationSetting extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'key', 'is_enabled'];

    protected $attributes = ['is_enabled' => true];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getNameAttribute(): string
    {
        return __('notification_keys.'.$this->key);
    }
}
