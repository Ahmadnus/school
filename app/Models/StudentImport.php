<?php

namespace App\Models;

use App\Enums\ImportRowStatus;
use App\Enums\ImportStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentImport extends Model
{
    use HasFactory;

    /** The system fields the mapping screen offers, and which are required. */
    public const FIELDS = [
        'first_name' => true,
        'last_name' => false,
        'external_id' => false,
        'birth_date' => false,
        'gender' => false,
        'nationality' => false,
        'blood_type' => false,
        'address' => false,
        'building' => false,
        'medical_notes' => false,
        'guardian_name' => false,
        'guardian_phone' => false,
    ];

    protected $fillable = [
        'school_id',
        'academic_year_id',
        'section_id',
        'path',
        'original_name',
        'status',
        'headers',
        'mapping',
        'rows_count',
        'valid_count',
        'invalid_count',
        'imported_count',
        'error',
        'created_by',
    ];

    protected $attributes = [
        'status' => ImportStatus::Uploaded->value,
    ];

    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'headers' => 'array',
            'mapping' => 'array',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(StudentImportRow::class)->orderBy('row_number');
    }

    public function validRows(): HasMany
    {
        return $this->rows()->where('status', ImportRowStatus::Valid);
    }

    public function scopeOfSchool(Builder $query, int $schoolId): Builder
    {
        return $query->where('school_id', $schoolId);
    }

    public function isCommitted(): bool
    {
        return $this->status === ImportStatus::Committed;
    }
}
