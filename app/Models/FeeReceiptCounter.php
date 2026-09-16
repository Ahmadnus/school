<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * يمنح كل إيصال رقماً متسلسلاً داخل المدرسة تحت قفل صف، فلا يحصل أمينان
 * يقبضان في اللحظة نفسها على الرقم ذاته.
 */
class FeeReceiptCounter extends Model
{
    protected $primaryKey = 'school_id';

    public $incrementing = false;

    protected $fillable = ['school_id', 'next_number'];

    /** يجب أن تُستدعى داخل معاملة حتى يبقى القفل قائماً. */
    public static function reserve(int $schoolId): int
    {
        $counter = static::query()->lockForUpdate()->find($schoolId);

        if (! $counter) {
            $start = (int) FeePayment::query()->where('school_id', $schoolId)->max('receipt_number') + 1;
            $counter = static::create(['school_id' => $schoolId, 'next_number' => $start]);
            $counter = static::query()->lockForUpdate()->find($schoolId);
        }

        $number = (int) $counter->next_number;
        $counter->forceFill(['next_number' => $number + 1])->save();

        return $number;
    }

    public static function nextNumber(int $schoolId): int
    {
        return DB::transaction(fn () => static::reserve($schoolId));
    }
}
