<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Term extends Model
{
    use HasFactory;

    protected $fillable = ['academic_year_id', 'name', 'start_date', 'end_date', 'is_current'];

    protected function casts(): array
    {
        return [
            'start_date' => DateOnly::class,
            'end_date' => DateOnly::class,
            'is_current' => 'boolean',
        ];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class);
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }

    public function markAsCurrent(): void
    {
        DB::transaction(function () {
            static::query()
                ->where('academic_year_id', $this->academic_year_id)
                ->whereKeyNot($this->getKey())
                ->update(['is_current' => false]);

            $this->forceFill(['is_current' => true])->save();
        });
    }

    /**
     * فصل المدرسة الجاري — أو أوّل فصولها إن لم يُوسَم واحد.
     *
     * التراجع إلى الأوّل مقصود، وهو نفس ما يفعله `StudentSubjects`: مدرسةٌ
     * أنشأت فصولها ونسيت أن تُعلن الجاري منها ليست مدرسةً بلا فصل. وبلا هذا
     * التراجع كان توليد الجدول يرفض العمل بسبب خانةٍ لم يؤشّرها أحد.
     */
    public static function currentFor(int $schoolId): ?self
    {
        $year = AcademicYear::currentFor($schoolId);

        if ($year === null) {
            return null;
        }

        $terms = static::query()->where('academic_year_id', $year->id);

        return (clone $terms)->current()->first()
            ?? (clone $terms)->orderBy('start_date')->first();
    }
}
