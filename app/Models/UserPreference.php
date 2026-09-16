<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPreference extends Model
{
    use HasFactory;

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = ['user_id', 'locale', 'theme', 'attachment_save'];

    protected $attributes = [
        'locale' => 'ar',
        'theme' => 'system',
        'attachment_save' => 'ask',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
