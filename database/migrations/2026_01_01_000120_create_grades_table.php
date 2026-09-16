<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No fee column: fee_types.total_amount is the single source (decision 1-a).
        Schema::create('grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['school_id', 'name']);
            $table->index(['school_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grades');
    }
};
