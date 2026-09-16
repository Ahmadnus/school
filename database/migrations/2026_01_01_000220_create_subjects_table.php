<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A subject is always a (grade + term) pair: the list is filtered by both.
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('grading_method', ['numeric', 'descriptive'])->default('numeric');
            $table->decimal('max_score', 6, 2)->default(100);
            $table->decimal('pass_score', 6, 2)->default(50);
            $table->unsignedTinyInteger('periods_per_week')->default(3);
            $table->timestamps();

            $table->unique(['grade_id', 'term_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};
