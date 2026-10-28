<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الموارد البشرية: ملف الموظف (مسمّى وتعيين وراتب أساسي) وطلبات الإجازة
 * وصرف الرواتب. إضافة فقط — جدول المستخدمين نفسه لا يُعدَّل.
 *
 * الرواتب بالفلس. الدفعة لا تُحذف بل تُلغى بسبب، ومعها قيدها المحاسبي.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('job_title', 120)->nullable();
            $table->date('hired_on')->nullable();
            $table->unsignedBigInteger('base_salary_minor')->default(0);
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // annual | sick | unpaid | other
            $table->date('from_date');
            $table->date('to_date');
            $table->string('reason', 500)->nullable();
            $table->string('status', 12)->default('pending'); // pending | approved | rejected
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->timestamps();

            $table->index(['school_id', 'status']);
        });

        Schema::create('salary_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('month', 7); // YYYY-MM
            $table->unsignedBigInteger('base_minor');
            $table->unsignedBigInteger('bonus_minor')->default(0);
            $table->unsignedBigInteger('deduction_minor')->default(0);
            $table->unsignedBigInteger('net_minor');
            $table->string('method', 20)->default('cash');
            $table->date('paid_on');
            $table->string('note', 500)->nullable();
            $table->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['school_id', 'month']);
            $table->index(['user_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_payments');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('employee_profiles');
    }
};
