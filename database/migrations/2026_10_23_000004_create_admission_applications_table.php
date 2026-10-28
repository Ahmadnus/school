<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * القبول والتسجيل: طلب التحاق ← مقابلة ← قبول/رفض ← تسجيل (يصير طالباً).
 * جدول جديد فقط؛ الطالب لا يُنشأ إلا عند «التسجيل» ويُربط هنا بـ student_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->date('birth_date')->nullable();
            $table->string('gender', 10)->nullable();
            $table->foreignId('desired_grade_id')->nullable()->constrained('grades')->nullOnDelete();
            $table->string('guardian_name', 120)->nullable();
            $table->string('guardian_phone', 30)->nullable();
            $table->string('previous_school', 150)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->string('status', 12)->default('new'); // new|interview|accepted|rejected|enrolled|withdrawn
            $table->dateTime('interview_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['school_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_applications');
    }
};
