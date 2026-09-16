<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // NFC cards (decision 16-b). A uid is globally unique because the gate
        // reader only ever sees the uid.
        Schema::create('student_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('nfc_uid')->unique();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['student_id', 'is_active']);
        });

        // At most one active card per student.
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['sqlite', 'pgsql'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX student_cards_active_unique
                 ON student_cards (student_id) WHERE is_active'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_cards');
    }
};
