<?php

namespace App\Models;

use App\Enums\GateScanResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GateScan extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'nfc_uid',
        'student_id',
        'scanned_at',
        'result',
        'scanned_by',
    ];

    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime',
            'result' => GateScanResult::class,
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function scanner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by');
    }

    public function scopeOfSchool(Builder $query, int $schoolId): Builder
    {
        return $query->where('school_id', $schoolId);
    }
}
