<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->enum('scope', ['full_year', 'first_term', 'second_term'])->default('full_year');
            $table->date('enrolled_at');
            $table->boolean('transport_subscribed')->default(false);
            $table->enum('status', ['active', 'transferred', 'withdrawn'])->default('active');
            $table->timestamps();

            // One enrollment per student per year; moving sections edits the row.
            $table->unique(['student_id', 'academic_year_id']);
            $table->index(['section_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_enrollments');
    }
};
