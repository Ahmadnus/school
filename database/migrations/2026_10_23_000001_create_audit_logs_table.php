<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجلّ التدقيق: من غيّر ماذا ومتى. إضافة فقط — لا يمسّ أي جدول موجود، ولا
 * تُحدَّث صفوفه ولا تُحذف (يمنع ذلك النموذج نفسه).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('event', 16);
            $table->string('auditable_type', 64);
            $table->unsignedBigInteger('auditable_id');
            $table->string('label')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['school_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
