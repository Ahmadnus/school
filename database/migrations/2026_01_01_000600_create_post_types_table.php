<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The 13 types are fixed in the system; only is_enabled and the
        // minimum creating role are editable.
        Schema::create('post_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('group');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_enabled')->default(true);
            // Permissions cascade upwards: teacher < admin < super_admin.
            $table->enum('min_role', ['teacher', 'admin', 'super_admin'])->default('teacher');
            $table->boolean('requires_approval')->default(false);
            $table->timestamps();

            $table->unique(['school_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_types');
    }
};
