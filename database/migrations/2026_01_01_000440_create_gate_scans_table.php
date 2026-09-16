<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every scan is logged, including the ones that matched nothing
        // (decision 16-c), so student_id stays nullable.
        Schema::create('gate_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('nfc_uid');
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('scanned_at');
            $table->enum('result', ['accepted', 'unknown_card', 'revoked_card', 'duplicate']);
            $table->foreignId('scanned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['school_id', 'scanned_at']);
            $table->index('nfc_uid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_scans');
    }
};
