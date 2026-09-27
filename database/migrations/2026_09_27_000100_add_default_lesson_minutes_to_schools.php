<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * طول الحصّة الحقيقي للمعهد — ستّون دقيقة.
 *
 * كان الرقم يُكتب في كل ضبطة دوام على حدة، وقيمته الافتراضية في العمود ٤٥
 * دقيقة. و٤٥ **ليست** قاعدة عمل: هي افتراض قديم في الهجرة وفي الرقاقة
 * المؤشَّرة سلفاً في شاشة بناء الجدول، ولا يقرؤها شيء في النظام غير
 * `SectionDayHours::periods()`. فصار للمعهد رقمه المُعلَن مرّة واحدة.
 *
 * ودوام الشعبة يبقى قادراً على تجاوزه (`section_day_hours.period_minutes`):
 * شعبة صباحية وأخرى مسائية قد تختلفان. فهذا العمود يملأ الفراغ لا يُلغي
 * الاستثناء — ولذلك الصفوف القائمة تبقى على قيمها كما هي.
 *
 * إضافيّ ومُعطى قيمةً افتراضية، فلا يمسّ بيانات الإنتاج.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->unsignedSmallInteger('default_lesson_minutes')
                ->default(60)
                ->after('default_max_score');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('default_lesson_minutes');
        });
    }
};
