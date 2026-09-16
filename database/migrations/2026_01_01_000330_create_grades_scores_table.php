<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A null score is a blank cell in the entry grid: saving part of a class
        // is allowed, so the row may exist without a value.
        Schema::create('grades_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->decimal('score', 6, 2)->nullable();
            $table->foreignId('rubric_level_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('entered_at')->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['assessment_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grades_scores');
    }
};
