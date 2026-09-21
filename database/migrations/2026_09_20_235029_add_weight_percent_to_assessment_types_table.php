<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الوزن ينتقل من التقييم المفرد إلى **نوعه**.
 *
 * كان على كل تقييم: تضيف كويزاً فتكتب وزنه، وتضيف آخر فتكتبه ثانيةً، وإن
 * نسيت مرّة صار كل شيء متساوياً بصمت. والمدرسة لا تفكّر هكذا — تقول
 * «الكويزات ٢٠٪ والنصفي ٣٠٪ والنهائي ٥٠٪» مرّة واحدة في السنة.
 *
 * `null` يعني «بلا وزن مُعلَن»، فيتقاسم النوع الحصّة بالتساوي مع الأنواع
 * الأخرى — وهو سلوك النظام قبل هذه الهجرة، فحسابات البيانات القائمة لا
 * تتغيّر. ووزن التقييم المفرد يبقى موجوداً ويعلو على وزن نوعه عند الحاجة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_types', function (Blueprint $table) {
            $table->decimal('weight_percent', 5, 2)->nullable()->after('is_exam');
        });
    }

    public function down(): void
    {
        Schema::table('assessment_types', function (Blueprint $table) {
            $table->dropColumn('weight_percent');
        });
    }
};
