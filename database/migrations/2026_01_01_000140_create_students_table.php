<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No section_id: everything year-specific lives on student_enrollments (decision 19-b).
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('student_number');
            $table->string('external_id')->nullable();
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->date('birth_date')->nullable();
            $table->enum('gender', ['male', 'female'])->nullable();
            $table->string('nationality')->nullable();
            $table->string('blood_type', 8)->nullable();
            $table->text('address')->nullable();
            $table->string('building')->nullable();
            $table->text('medical_notes')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->unique(['school_id', 'student_number']);
            $table->unique(['school_id', 'external_id']);
            $table->index(['school_id', 'first_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
