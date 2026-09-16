<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Behaviour log of a student: positive notes, negative notes, warnings and
 * incidents. `type` and `status` are plain strings so a new value needs no
 * migration — App\Enums\BehaviorType / BehaviorStatus are the source of truth
 * (same reasoning as users.role).
 *
 * `visible_to_guardian` is an explicit opt-in per record: the guardian app
 * only ever sees records the school chose to share.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('behavior_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 32);
            $table->string('category', 100)->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('occurred_on');
            $table->text('action_taken')->nullable();
            $table->string('status', 32)->default('open');
            $table->boolean('visible_to_guardian')->default(false);
            $table->timestamps();

            $table->index(['student_id', 'occurred_on']);
            $table->index(['student_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('behavior_records');
    }
};
