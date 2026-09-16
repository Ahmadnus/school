<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * لوحة الشرف: طلاب يكرّمهم أساتذتهم، وكل الأهالي يرونهم.
 *
 * التكريم اختيار بشري لا حساب آلي: الأول في العلامات ليس دائماً الأولى
 * بالتكريم، والطالب الذي قفز من 40 إلى 75 يستحق أكثر ممن ثبت على 95.
 *
 * ما يُعرض للعموم اسم وصف وسبب فقط — بلا علامات. اللوحة تحفّز بلا أن تنشر
 * درجات طالب على أهالي غيره.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('honor_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained()->nullOnDelete();
            // المادة التي كُرِّم فيها، أو null لتكريم عام من الإدارة.
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('awarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 20);
            $table->string('reason', 300);
            // مسوّدة حتى يُنشر: التكريم يظهر للأهالي عند النشر لا عند الكتابة.
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'published_at']);
            $table->index(['student_id', 'published_at']);
            // الأستاذ نفسه لا يكرّم الطالب مرتين في المادة نفسها بالسبب نفسه.
            $table->unique(['student_id', 'subject_id', 'awarded_by', 'reason'], 'honor_entries_no_duplicate');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('honor_entries');
    }
};
