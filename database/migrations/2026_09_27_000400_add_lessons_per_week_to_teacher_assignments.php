<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * عدد حصص الإسناد في الأسبوع.
 *
 * الإسناد اليوم ثلاثيّ (أستاذ + مادة + شعبة) ولا يقول **كم** حصّة. والمولّد
 * لا يستطيع أن يجدول بلا هذا الرقم: «رياضيات للتاسع أ» ليست مطلباً، بل
 * «أربع حصص رياضيات للتاسع أ» هي المطلب.
 *
 * `null` مقصود ولا يعني صفراً: يعني «خُذ ما على المادة»
 * (`subjects.periods_per_week`، افتراضه ٣). فالصفوف القائمة في الإنتاج — تسعة
 * إسنادات — تبقى صحيحة بلا أن يُدخل أحدٌ رقماً لها يدويّاً، ومن أراد لشعبةٍ
 * عدداً مختلفاً عن أختها كتبه هنا وحدها.
 *
 * إضافيّ وقابل للإفراغ، فلا يمسّ بيانات الإنتاج.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_assignments', function (Blueprint $table) {
            $table->unsignedTinyInteger('lessons_per_week')
                ->nullable()
                ->after('section_id');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_assignments', function (Blueprint $table) {
            $table->dropColumn('lessons_per_week');
        });
    }
};
