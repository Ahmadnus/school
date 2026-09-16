<?php

namespace App\Models;

use App\Enums\AttachmentOwner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One polymorphic table for every owner type (decision 12-a). */
class Attachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'owner_type',
        'owner_id',
        'path',
        'name',
        'mime',
        'size',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'owner_type' => AttachmentOwner::class,
            'size' => 'integer',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getUrlAttribute(): string
    {
        return asset('storage/'.$this->path);
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }

    public function scopeOfSchool(Builder $query, int $schoolId): Builder
    {
        return $query->where('school_id', $schoolId);
    }

    /** The gallery is this filter, not a table of its own (decision 13-a). */
    public function scopeImages(Builder $query): Builder
    {
        return $query->where('mime', 'like', 'image/%');
    }
}
