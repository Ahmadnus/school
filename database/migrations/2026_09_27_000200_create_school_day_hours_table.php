<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دوام **المدرسة** — الحدّ الخارجي الذي لا تُوضَع حصّة خارجه.
 *
 * ثلاثة مفاهيم كانت تُخلَط، وهذا الجدول يفصل أوّلها:
 *
 *  1. دوام المدرسة (هنا)     — متى يفتح المعهد أبوابه.
 *  2. دوام الشعبة            — `section_day_hours`، يضيّق دوام المدرسة لشعبة
 *                              بعينها (صباحية ومسائية في المبنى نفسه).
 *  3. تفرّغ الأستاذ           — `teacher_availability`، متى يستطيع هو.
 *
 * النافذة الفعليّة تقاطُع الثلاثة، والحصّة تصحّ إن دخلت **بكاملها** فيه.
 *
 * ويوم بلا صفٍّ هنا يوم مغلق للمعهد كلّه — كما في `section_day_hours`، بلا
 * عمود `is_working` يمكن أن يناقض وجود الصفّ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_day_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            // 0=الأحد … 6=السبت، كما في App\Enums\Weekday.
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->timestamps();

            $table->unique(['school_id', 'day_of_week'], 'school_day_hours_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_day_hours');
    }
};
