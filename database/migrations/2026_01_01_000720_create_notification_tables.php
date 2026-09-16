<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Two levels, and delivery requires both (decision 8-a).
        Schema::create('school_notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->enum('app', ['staff', 'guardian']);
            $table->string('key');
            $table->string('group');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(['school_id', 'app', 'key']);
        });

        Schema::create('user_notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'key']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('title');
            $table->text('body')->nullable();
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'is_read']);
        });

        Schema::create('user_preferences', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('locale', 5)->default('ar');
            $table->enum('theme', ['light', 'dark', 'system'])->default('system');
            $table->enum('attachment_save', ['ask', 'always', 'never'])->default('ask');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('user_notification_settings');
        Schema::dropIfExists('school_notification_settings');
    }
};
