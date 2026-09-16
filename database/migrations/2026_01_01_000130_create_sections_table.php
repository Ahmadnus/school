<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A section is created per academic year (decision 19-b).
        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('capacity')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['grade_id', 'academic_year_id', 'name']);
            $table->index(['academic_year_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sections');
    }
};
