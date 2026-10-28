<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;

/** تصنيف إيراد أو مصروف («إيجار»، «رواتب»، «تبرعات»…). */
class LedgerCategory extends Model
{
    use Audited;

    public const INCOME = 'income';

    public const EXPENSE = 'expense';

    protected $fillable = ['school_id', 'kind', 'name', 'system_key', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * تصنيف النظام (مثل `expense.salaries`) — يُنشأ أو يُسترجع. إن وُجد تصنيف
     * بالاسم نفسه أنشأه المستخدم يدوياً يُربط به المفتاح بدل أن يتعارض الاسم.
     */
    public static function system(int $schoolId, string $key): self
    {
        self::ensureDefaults($schoolId);

        $found = self::where('school_id', $schoolId)->where('system_key', $key)->first();
        if ($found !== null) {
            return $found;
        }

        [$kind, $slug] = explode('.', $key, 2);
        $name = __("accounting.defaults.{$kind}.{$slug}");
        $byName = self::where('school_id', $schoolId)->where('kind', $kind)->where('name', $name)->first();
        if ($byName !== null) {
            $byName->update(['system_key' => $key]);

            return $byName;
        }

        return self::create(['school_id' => $schoolId, 'kind' => $kind, 'name' => $name, 'system_key' => $key]);
    }

    /** تصنيفات أولية تُنشأ مرة واحدة حين تكون مدرسة بلا أي تصنيف. */
    public static function ensureDefaults(int $schoolId): void
    {
        if (self::where('school_id', $schoolId)->exists()) {
            return;
        }

        $defaults = [
            self::EXPENSE => ['rent', 'salaries', 'utilities', 'supplies', 'maintenance', 'marketing', 'other'],
            self::INCOME => ['donations', 'activities', 'books', 'other'],
        ];

        foreach ($defaults as $kind => $keys) {
            foreach ($keys as $key) {
                self::create([
                    'school_id' => $schoolId,
                    'kind' => $kind,
                    'name' => __("accounting.defaults.{$kind}.{$key}"),
                    'system_key' => "{$kind}.{$key}",
                ]);
            }
        }
    }
}
