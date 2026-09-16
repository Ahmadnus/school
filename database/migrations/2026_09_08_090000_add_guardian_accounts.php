<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives guardians a way to sign in, which the guardian app needs.
 *
 * A guardian was previously just a name and a phone with no account, so a
 * targeted post had no device to reach. The link is nullable: a guardian
 * recorded by the office stays valid until they activate the app.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The 'guardian' role value needs no schema change: a follow-up
        // migration widens users.role to a plain string, and the enum
        // App\Enums\UserRole is the single source of valid values.

        Schema::table('guardians', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->unique()
                ->after('school_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('guardians', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });

    }
};
