<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Casts\MoneyCast;
use App\Models\Concerns\Audited;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ملف الموظف: مسمّى وظيفي وتاريخ تعيين وراتب أساسي. */
class EmployeeProfile extends Model
{
    use Audited;

    protected $fillable = ['school_id', 'user_id', 'job_title', 'hired_on', 'base_salary_minor', 'notes'];

    protected function casts(): array
    {
        return [
            'hired_on' => DateOnly::class,
            'base_salary_minor' => MoneyCast::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function baseSalary(): Money
    {
        return $this->base_salary_minor;
    }
}
