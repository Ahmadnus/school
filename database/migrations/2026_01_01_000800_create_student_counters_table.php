<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Replaces the max()+1 lookup on students.student_number: a bulk import
        // allocates numbers under a row lock, so concurrent runs cannot collide.
        Schema::create('student_counters', function (Blueprint $table) {
            $table->foreignId('school_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_counters');
    }
};
