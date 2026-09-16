<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('body')->nullable();
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['draft', 'pending', 'published', 'rejected'])->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'status']);
            $table->index(['author_id', 'status']);
        });

        // Targeting scope. target_id is null for the whole school.
        Schema::create('post_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->enum('scope', ['school', 'grade', 'section', 'student', 'subject']);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->timestamps();

            $table->index(['post_id', 'scope']);
            $table->index(['scope', 'target_id']);
        });

        // Full approval trail (decision 9-a).
        Schema::create('post_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->enum('decision', ['approved', 'rejected']);
            $table->text('note')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->index('post_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_approvals');
        Schema::dropIfExists('post_targets');
        Schema::dropIfExists('posts');
    }
};
