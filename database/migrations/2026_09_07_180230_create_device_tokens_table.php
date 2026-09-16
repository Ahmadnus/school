<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FCM registration tokens, one row per device a user signs in on.
 *
 * Firebase rotates the token on its own, so the app re-registers whenever it
 * receives a new one; the unique index keeps a device to a single row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 512)->unique();
            // android | ios | web — lets the payload be tuned per platform.
            $table->string('platform', 20)->default('unknown');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
