<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Replaces teacher_sections + teacher_subjects with one triple (decision 15-b).
        Schema::create('teacher_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['staff_id', 'subject_id', 'section_id']);
            $table->index(['subject_id', 'section_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_assignments');
    }
};
