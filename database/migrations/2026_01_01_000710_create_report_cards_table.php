<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->text('supervisor_notes')->nullable();
            // Null while a draft: the average is computed live until publish,
            // which is why the UI shows "المعدّل: –" (decision 11-c).
            $table->decimal('average', 6, 2)->nullable();
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['student_id', 'term_id']);
            $table->index(['academic_year_id', 'status']);
        });

        // Written only at publish time; a draft renders from grades_scores.
        Schema::create('report_card_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->decimal('score', 6, 2)->nullable();
            $table->string('grade_label')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['report_card_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_card_lines');
        Schema::dropIfExists('report_cards');
    }
};
