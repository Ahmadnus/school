<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Allocates students.student_number per school (decision 20-a).
 *
 * The previous max()+1 lookup was fine for one form at a time but could hand
 * the same number to two concurrent writers; a bulk import makes that likely,
 * so allocation now happens under a row lock.
 */
class StudentCounter extends Model
{
    protected $primaryKey = 'school_id';

    public $incrementing = false;

    protected $fillable = ['school_id', 'next_number'];

    /** Reserves the next number, or a contiguous block of them. */
    public static function reserve(int $schoolId, int $count = 1): int
    {
        return DB::transaction(function () use ($schoolId, $count) {
            $counter = static::query()
                ->lockForUpdate()
                ->find($schoolId);

            if (! $counter) {
                // Seed from whatever is already in the table so an existing
                // school keeps its sequence.
                $start = (int) Student::query()->where('school_id', $schoolId)->max('student_number') + 1;

                $counter = static::create([
                    'school_id' => $schoolId,
                    'next_number' => $start,
                ]);
            }

            $first = $counter->next_number;
            $counter->forceFill(['next_number' => $first + $count])->save();

            return $first;
        });
    }
}
