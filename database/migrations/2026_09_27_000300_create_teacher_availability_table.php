<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تفرّغ الأستاذ: متى يستطيع هو أن يُدرّس، لكل يوم على حدة.
 *
 * **مدًى لا خانة.** الشاشة تُؤشّر على شبكة نصف ساعة (٠٨:٠٠ ← ١٨:٠٠، أربعون
 * خانة)، لكن الخانات المتّصلة تُخزَّن مدًى واحداً: «١١:٣٠ ← ١٣:٠٠» صفٌّ واحد
 * لا ثلاثة. أستاذٌ متفرّغ يوماً كاملاً يكلّف صفّاً واحداً بدل عشرين، والمولّد
 * يقارن مدًى بمدًى بدل أن يجمع خانات متجاورة في كل محاولة.
 *
 * ومن أشّر خانات متباعدة (صباحاً ثمّ مساءً) فله صفٌّ لكل قطعة متّصلة.
 *
 * **ونصف الساعة دقّة التأشير لا طول الحصّة.** الحصّة ستّون دقيقة في هذا
 * المعهد (`schools.default_lesson_minutes`)، فتفرّغٌ من ١١:٣٠ إلى ١٣:٠٠ يقبل
 * حصّةً واحدة تبدأ ١١:٣٠ أو ١٢:٠٠، ولا يقبل ثلاثاً.
 *
 * `day_of_week` بلا صفّ = الأستاذ غير متفرّغ ذلك اليوم. لا عمود `is_available`:
 * عمودٌ كهذا يستطيع أن يناقض وجود الصفّ نفسه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('users')->cascadeOnDelete();
            // 0=الأحد … 6=السبت، كما في App\Enums\Weekday.
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->timestamps();

            // مدًى واحد لكل بداية في اليوم — يمنع تكرار الصفّ نفسه مرّتين.
            $table->unique(
                ['staff_id', 'day_of_week', 'starts_at'],
                'teacher_availability_unique',
            );
            $table->index(['staff_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_availability');
    }
};
