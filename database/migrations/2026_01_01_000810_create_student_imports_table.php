<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            // The target is chosen before the file is uploaded: every imported
            // student is enrolled into this section for this year.
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->enum('status', ['uploaded', 'mapped', 'committed', 'failed'])->default('uploaded');
            $table->json('headers')->nullable();
            $table->json('mapping')->nullable();
            $table->unsignedInteger('rows_count')->default(0);
            $table->unsignedInteger('valid_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['school_id', 'status']);
        });

        // The review screen: one row per file line, kept until the import is
        // committed so every line can be inspected before it is accepted.
        Schema::create('student_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('raw');
            $table->json('mapped')->nullable();
            $table->json('errors')->nullable();
            $table->enum('status', ['pending', 'valid', 'invalid', 'imported', 'skipped'])->default('pending');
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['student_import_id', 'row_number']);
            $table->index(['student_import_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_import_rows');
        Schema::dropIfExists('student_imports');
    }
};
