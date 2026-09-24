<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تصحيح مبلغ أُدخل خطأ.
 *
 * الإيصال لا يُعدَّل ولا يُحذف — التصحيح يُلغي الخطأ ويصدر إيصالاً جديداً،
 * وهذا العمود هو الخيط بينهما: من الإيصال الجديد إلى الذي صحّحه. بدونه يرى
 * المدقّق إلغاءً ودفعةً متجاورين بلا ما يربطهما.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_payments', function (Blueprint $table) {
            $table->foreignId('corrects_payment_id')->nullable()->after('idempotency_key')
                ->constrained('fee_payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fee_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('corrects_payment_id');
        });
    }
};
