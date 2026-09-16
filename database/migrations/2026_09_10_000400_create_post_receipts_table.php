<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who has opened (read) and who has explicitly confirmed an announcement.
 *
 * Only states the apps can report truthfully are stored: `read_at` when the
 * post detail is opened, `confirmed_at` when the reader taps confirm on a
 * post that asks for it. There is no "delivered" — nothing in the pipeline
 * can prove delivery, so it is not faked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(['post_id', 'user_id']);
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->boolean('requires_confirmation')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('requires_confirmation');
        });

        Schema::dropIfExists('post_receipts');
    }
};
