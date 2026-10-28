<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المحاسبة: إيرادات ومصاريف المعهد خارج الأقساط، بتصنيفات.
 *
 * المبالغ بالفلس (BIGINT) كما في الأقساط. القيد لا يُحذف: يُلغى بسبب،
 * فتبقى أرقام الدفاتر قابلة للتدقيق. إضافة فقط — لا يمسّ جداول الأقساط.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // income | expense
            $table->string('name', 80);
            // مفتاح ثابت للتصنيفات التي يكتب فيها النظام نفسه (الرواتب)، لا يتأثر بإعادة التسمية.
            $table->string('system_key', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['school_id', 'kind', 'name']);
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_category_id')->constrained('ledger_categories');
            $table->string('kind', 10);
            $table->date('entry_date');
            $table->unsignedBigInteger('amount_minor');
            $table->string('method', 20)->default('cash');
            $table->string('reference', 120)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['school_id', 'entry_date']);
            $table->unique(['school_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_categories');
    }
};
