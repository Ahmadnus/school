<?php

namespace App\Models;

use App\Enums\ImportRowStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentImportRow extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_import_id',
        'row_number',
        'raw',
        'mapped',
        'errors',
        'status',
        'student_id',
    ];

    protected $attributes = [
        'status' => ImportRowStatus::Pending->value,
    ];

    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'mapped' => 'array',
            'errors' => 'array',
            'status' => ImportRowStatus::class,
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(StudentImport::class, 'student_import_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
