<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\AttendanceSessionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceSession extends Model
{
    use HasFactory;


    protected $fillable = ['section_id', 'date', 'status', 'submitted_by', 'submitted_at'];

    protected $attributes = [
        'status' => AttendanceSessionStatus::Draft->value,
    ];

    protected function casts(): array
    {
        return [
            'date' => DateOnly::class,
            'status' => AttendanceSessionStatus::class,
            'submitted_at' => 'datetime',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}
