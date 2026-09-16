<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns `users.role` from a database enum into a plain string.
 *
 * The enum had to be redefined in the schema every time a role was added, and
 * each engine does that differently — MySQL needs MODIFY COLUMN, SQLite
 * enforces a CHECK constraint that requires rebuilding the table. The set of
 * valid roles already lives in App\Enums\UserRole, which the model casts to,
 * so the database column need only store the string.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 32)->default('teacher')->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['super_admin', 'admin', 'teacher', 'driver'])
                ->default('teacher')
                ->change();
        });
    }
};
