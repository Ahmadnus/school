<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * محاولة توليد جدول: ما طُلب، وما وُجد، وهل نجحت.
 *
 * يوجد لسببين لا لأرشفة:
 *
 *  1. **التحليل قبل التوليد.** «الأستاذ أحمد يحتاج ٨ حصص وله ٦ خانات صالحة»
 *     نصٌّ يجب أن يبقى ليُقرأ ويُعالَج، لا رسالةَ خطأ تختفي مع إغلاق الشاشة.
 *  2. **إعادة التوليد بلا تخريب.** الحصص المولَّدة تُوسَم بالمحاولة التي
 *     أنشأتها (`schedule_slots.timetable_run_id`)، فإعادة التوليد تحذف
 *     المولَّد وحده وتترك ما بناه المستخدم بيده — وهو جدول قائم في الإنتاج
 *     اليوم، حذفه لأن أحداً ضغط «أعد التوليد» خسارةٌ لا تُستعاد.
 *
 * `conflicts` و`stats` مخزَّنان JSON: تقرير التعارض قائمةُ أسبابٍ مختلفة
 * الشكل (أستاذ، شعبة، مادة)، وجدولٌ لكل شكل يعني ثلاثة جداول تُقرأ معاً دائماً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetable_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            // analyzed = فُحصت الجدوى ولم يُكتب شيء بعد.
            // generated = نجحت وكُتبت حصصها.
            // infeasible = ثبت أنّ الجدول مستحيل، والسبب في `conflicts`.
            $table->enum('status', ['analyzed', 'generated', 'infeasible'])
                ->default('analyzed');
            $table->foreignId('requested_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('lesson_minutes');
            /** إحصاءات الجدوى: الخانات المتاحة، الحصص المطلوبة، سعة كل طرف. */
            $table->json('stats')->nullable();
            /** أسباب الاستحالة أو التعارض، كلٌّ باسم من سبّبه. */
            $table->json('conflicts')->nullable();
            $table->unsignedSmallInteger('lessons_placed')->default(0);
            $table->unsignedSmallInteger('lessons_required')->default(0);
            $table->timestamps();

            $table->index(['school_id', 'term_id', 'status']);
        });

        Schema::table('schedule_slots', function (Blueprint $table) {
            // `null` = حصّة بناها المستخدم بيده. إعادة التوليد لا تمسّها.
            $table->foreignId('timetable_run_id')->nullable()->after('staff_id')
                ->constrained('timetable_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('schedule_slots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('timetable_run_id');
        });

        Schema::dropIfExists('timetable_runs');
    }
};
