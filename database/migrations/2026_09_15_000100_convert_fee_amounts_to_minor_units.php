<?php

use App\Support\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تحويل كل أعمدة المال في نظام الأقساط من decimal(12,2) إلى BIGINT بالفلس.
 *
 * السبب: decimal في قاعدة البيانات يعود إلى PHP كنص ثم يُحوَّل float عند أي
 * جمع أو مقارنة، فيتراكم انحراف التقريب وتظهر فروق لا مصدر لها في الدفاتر.
 * بالفلس الحساب كله على int: الجمع دقيق والمقارنة دقيقة بلا هامش تسامح.
 */
return new class extends Migration
{
    /** الجدول => [العمود العشري القديم => العمود الجديد بالفلس] */
    private const COLUMNS = [
        'fee_types' => ['total_amount' => 'total_minor'],
        'fee_type_installments' => ['amount' => 'amount_minor'],
        'fee_plans' => ['total_amount' => 'total_minor', 'discount_amount' => 'discount_minor'],
        'fee_plan_installments' => ['amount' => 'amount_minor'],
        'fee_payments' => ['amount' => 'amount_minor'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $map) {
            Schema::table($table, function (Blueprint $blueprint) use ($map) {
                foreach ($map as $new) {
                    $blueprint->bigInteger($new)->default(0);
                }
            });

            foreach (DB::table($table)->get() as $row) {
                $values = [];

                foreach ($map as $old => $new) {
                    $values[$new] = Money::fromDecimal($row->{$old} ?? 0)->minor;
                }

                DB::table($table)->where('id', $row->id)->update($values);
            }

            Schema::table($table, function (Blueprint $blueprint) use ($map) {
                $blueprint->dropColumn(array_keys($map));
            });
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $map) {
            Schema::table($table, function (Blueprint $blueprint) use ($map) {
                foreach ($map as $old => $new) {
                    $blueprint->decimal($old, 12, 2)->default(0);
                }
            });

            foreach (DB::table($table)->get() as $row) {
                $values = [];

                foreach ($map as $old => $new) {
                    $values[$old] = Money::fromMinor((int) $row->{$new})->toDecimal();
                }

                DB::table($table)->where('id', $row->id)->update($values);
            }

            Schema::table($table, function (Blueprint $blueprint) use ($map) {
                $blueprint->dropColumn(array_values($map));
            });
        }
    }
};
