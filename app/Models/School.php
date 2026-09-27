<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class School extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'website',
        'logo_path',
        'currency',
        'phone_country_code',
        'absence_warning_threshold',
        'default_pass_score',
        'default_max_score',
        'default_lesson_minutes',
    ];

    protected function casts(): array
    {
        return [
            'absence_warning_threshold' => 'integer',
            'default_pass_score' => 'decimal:2',
            'default_max_score' => 'decimal:2',
            'default_lesson_minutes' => 'integer',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** دوام المعهد لكل يوم — الحدّ الخارجي لكل جدول. */
    public function dayHours(): HasMany
    {
        return $this->hasMany(SchoolDayHours::class);
    }

    /**
     * طول الحصّة المُعتمد، بالدقائق.
     *
     * يُقرأ من هنا لا من ثابتٍ في الكود: كان الرقم ٤٥ افتراضاً قديماً في
     * عمود `section_day_hours.period_minutes` وفي رقاقة الشاشة، وليس قاعدة
     * عمل. ومعهدنا حصّته ستّون دقيقة.
     */
    public function lessonMinutes(): int
    {
        return max(5, (int) ($this->default_lesson_minutes ?: 60));
    }
}
