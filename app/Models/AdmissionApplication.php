<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** طلب التحاق بالمعهد. المسار: new → interview → accepted → enrolled (أو rejected/withdrawn). */
class AdmissionApplication extends Model
{
    use Audited;

    public const STATUSES = ['new', 'interview', 'accepted', 'rejected', 'enrolled', 'withdrawn'];

    /** @var array<string, list<string>> الانتقالات المسموحة يدوياً؛ «enrolled» لا يصل إلا من التسجيل. */
    public const TRANSITIONS = [
        'new' => ['interview', 'accepted', 'rejected', 'withdrawn'],
        'interview' => ['accepted', 'rejected', 'withdrawn'],
        'accepted' => ['withdrawn'],
        'rejected' => ['new'],
        'withdrawn' => ['new'],
        'enrolled' => [],
    ];

    protected $fillable = [
        'school_id', 'first_name', 'last_name', 'birth_date', 'gender', 'desired_grade_id',
        'guardian_name', 'guardian_phone', 'previous_school', 'notes', 'created_by',
    ];

    protected $attributes = ['status' => 'new'];

    protected function casts(): array
    {
        return [
            'birth_date' => DateOnly::class,
            'interview_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class, 'desired_grade_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function canMoveTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function auditLabel(): ?string
    {
        return $this->fullName();
    }
}
