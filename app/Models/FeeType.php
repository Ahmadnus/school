<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\Status;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeType extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'name',
        'grade_id',
        'subject_id',
        'is_transport',
        'total_minor',
        'notes',
        'is_default',
        'status',
    ];

    protected $attributes = [
        'is_transport' => false,
        'is_default' => false,
        'status' => Status::Active->value,
    ];

    protected function casts(): array
    {
        return [
            'total_minor' => MoneyCast::class,
            'is_transport' => 'boolean',
            'is_default' => 'boolean',
            'status' => Status::class,
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(FeeTypeInstallment::class)->orderBy('sort_order');
    }

    public function plans(): HasMany
    {
        return $this->hasMany(FeePlan::class);
    }

    public function scopeOfSchool(Builder $query, int $schoolId): Builder
    {
        return $query->where('school_id', $schoolId);
    }

    public function totalAmount(): Money
    {
        return $this->total_minor;
    }

    /** سطر التحقق الأخضر في الفورم: مجموع الأقساط يجب أن يساوي الإجمالي. */
    public function installmentsTotal(): Money
    {
        if ($this->relationLoaded('installments')) {
            return Money::sum($this->installments->map(fn (FeeTypeInstallment $i) => $i->amount()));
        }

        return Money::fromMinor((int) $this->installments()->sum('amount_minor'));
    }
}
