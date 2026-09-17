<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مواد التسجيل — الطالب الذي يدرس **مواد بعينها** لا الخطة الكاملة.
 *
 * معاهد كثيرة تسجّل طالباً في مادّتين أو ثلاث لا في البرنامج كلّه، ورسومه
 * عندها تُحسب على ما اختاره. غياب الصفوف هنا يعني الخطة الكاملة — وهي
 * الحالة الغالبة، فلا تُثقَل بصفوف لكل مادة في المنهاج.
 *
 * التسجيل هو المرساة لا الطالب: الطالب قد يسجّل مواد في سنة ويكمل البرنامج
 * في التي بعدها، فربط المواد بالسنة الدراسية يحفظ التاريخ صحيحاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_enrollment_id')
                ->constrained('student_enrollments')
                ->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // المادة الواحدة لا تُسجَّل مرّتين في التسجيل نفسه.
            $table->unique(
                ['student_enrollment_id', 'subject_id'],
                'enrollment_subjects_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_subjects');
    }
};
