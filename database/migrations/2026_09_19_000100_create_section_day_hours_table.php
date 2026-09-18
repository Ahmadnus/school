<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دوام الشعبة في اليوم — منه تُشتقّ أوقات الحصص بدل كتابتها حصّة حصّة.
 *
 * بناء الجدول كان يطلب لكل حصّة: يوماً ووقت بدء ووقت انتهاء ورقم حصّة
 * ومادة وأستاذاً. ستّة حقول × ست حصص × خمسة أيام = مئة وثمانون إدخالاً
 * لشعبة واحدة. فصار الوقت يُضبط مرّة لليوم، وتبقى المادة وحدها لكل حصّة.
 *
 * لكل شعبة دوامها: شعبة صباحية وأخرى مسائية في المبنى نفسه أمر شائع.
 * ويوم بلا صفّ هنا يوم عطلة لتلك الشعبة — فالسبت يُضبط لمن يداوم فيه وحده.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('section_day_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            // 0=الأحد … 6=السبت، كما في App\Enums\Weekday.
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at');
            $table->time('ends_at');
            // طول الحصّة والفاصل بينها وبين التالية، بالدقائق.
            $table->unsignedSmallInteger('period_minutes')->default(45);
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->timestamps();

            $table->unique(['section_id', 'day_of_week'], 'section_day_hours_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('section_day_hours');
    }
};
