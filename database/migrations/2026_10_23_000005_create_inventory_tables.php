<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الأصول الثابتة (أثاث وأجهزة) والمخزون (قرطاسية ومستهلكات) مع حركاته.
 * إضافة فقط. الحركة لا تُحذف ولا تُعدَّل: التصحيح بحركة «تسوية» جديدة،
 * والرصيد المخزَّن في الصنف يُحدَّث مع كل حركة داخل العملية نفسها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('code', 40)->nullable();
            $table->string('category', 80)->nullable();
            $table->string('location', 120)->nullable();
            $table->date('purchased_on')->nullable();
            $table->unsignedBigInteger('cost_minor')->default(0);
            $table->string('status', 12)->default('active'); // active | maintenance | retired
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['school_id', 'status']);
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('sku', 40)->nullable();
            $table->string('unit', 30)->default('قطعة');
            $table->integer('quantity')->default(0);
            $table->unsignedInteger('min_quantity')->default(0);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index('school_id');
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->string('type', 10); // in | out | adjust
            $table->integer('delta'); // موقَّع: + إدخال، − إخراج
            $table->integer('balance_after');
            $table->string('reason', 255)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['item_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('assets');
    }
};
