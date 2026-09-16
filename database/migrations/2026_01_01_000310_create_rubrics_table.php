<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rubrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['school_id', 'name']);
        });

        // Levels of a descriptive rubric (decision 5-a) — the form for these is
        // one of the screens the spec lists as missing.
        Schema::create('rubric_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rubric_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('value', 6, 2)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['rubric_id', 'name']);
            $table->index(['rubric_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rubric_levels');
        Schema::dropIfExists('rubrics');
    }
};
