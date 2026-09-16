<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->unsignedTinyInteger('period_number');
            $table->string('room')->nullable();
            $table->timestamps();

            // اسم صريح: الاسم التلقائي من لارافيل يبلغ ٦٦ حرفاً، وMySQL يرفض ما تجاوز ٦٤
            // (SQLite يقبله، فلم يظهر العطل إلاّ عند الانتقال).
            $table->unique(
                ['section_id', 'term_id', 'day_of_week', 'period_number'],
                'schedule_slots_slot_unique',
            );
            $table->index(['staff_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_slots');
    }
};
