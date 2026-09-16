<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // total_amount is the single source of the fee figure (decision 1-a);
        // grades carry no fee column.
        Schema::create('fee_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // null grade_id is the "no grade" default of the form.
            $table->foreignId('grade_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_transport')->default(false);
            $table->decimal('total_amount', 12, 2);
            $table->text('notes')->nullable();
            $table->boolean('is_default')->default(false);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->unique(['school_id', 'name']);
            $table->index(['school_id', 'grade_id']);
        });

        Schema::create('fee_type_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_type_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->date('due_date');
            $table->decimal('amount', 12, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['fee_type_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_type_installments');
        Schema::dropIfExists('fee_types');
    }
};
