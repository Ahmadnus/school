<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            // Historical reference only: editing the type never touches the plan
            // (decision 2-a). Null means a custom plan.
            $table->foreignId('fee_type_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('total_amount', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->boolean('payment_reminders')->default(true);
            $table->enum('status', ['active', 'cancelled'])->default('active');
            $table->timestamps();

            $table->index(['student_id', 'academic_year_id']);
        });

        // Copied from fee_type_installments at creation, never referenced back.
        Schema::create('fee_plan_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->date('due_date');
            $table->decimal('amount', 12, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['fee_plan_id', 'sort_order']);
        });

        Schema::create('fee_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('installment_id')->nullable()
                ->constrained('fee_plan_installments')->nullOnDelete();
            $table->date('paid_on');
            $table->decimal('amount', 12, 2);
            $table->string('description')->nullable();
            $table->string('reference')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['fee_plan_id', 'paid_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_payments');
        Schema::dropIfExists('fee_plan_installments');
        Schema::dropIfExists('fee_plans');
    }
};
