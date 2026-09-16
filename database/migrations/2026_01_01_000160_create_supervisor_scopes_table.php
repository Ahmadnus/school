<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Supervision is scoped to sections (decision 7-a).
        Schema::create('supervisor_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['staff_id', 'section_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervisor_scopes');
    }
};
