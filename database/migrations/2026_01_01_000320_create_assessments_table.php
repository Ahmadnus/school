<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_type_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('max_score', 6, 2)->default(100);
            $table->decimal('weight_percent', 5, 2)->default(0);
            $table->timestamps();

            $table->unique(['subject_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessments');
    }
};
