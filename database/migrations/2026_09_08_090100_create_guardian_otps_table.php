<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time codes for guardian sign-in.
 *
 * Guardians never manage a password: the office records a phone number, and
 * the app proves ownership of it with a short code. Codes are stored hashed
 * so a database leak cannot be replayed, and each row tracks its own attempt
 * count so a code can be burned without touching the others.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guardian_otps', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->index();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guardian_otps');
    }
};
