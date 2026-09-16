<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Listed in §2.1 but only needed once the calendar union exists
        // (decision 10-a), so it lands with the reports group.
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamps();

            $table->index(['school_id', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
