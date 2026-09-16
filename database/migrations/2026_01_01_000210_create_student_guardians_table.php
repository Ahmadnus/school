<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_guardians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guardian_id')->constrained()->cascadeOnDelete();
            $table->enum('relation', ['father', 'mother', 'custodian']);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['student_id', 'guardian_id']);
        });

        // At most one primary contact per student (decision 14-a). A partial unique
        // index enforces it where the driver supports one; App\Models\StudentGuardian
        // enforces it in code on every driver.
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['sqlite', 'pgsql'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX student_guardians_primary_unique
                 ON student_guardians (student_id) WHERE is_primary'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_guardians');
    }
};
