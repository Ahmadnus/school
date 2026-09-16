<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_exam')->default(false);
            $table->timestamps();

            $table->unique(['school_id', 'name']);
            $table->index(['school_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_types');
    }
};
