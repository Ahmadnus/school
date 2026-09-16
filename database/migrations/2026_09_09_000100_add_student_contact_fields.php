<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contact details the student profile needs: the student's own phone/email
 * (older students), an emergency contact, and an email on the guardian.
 *
 * All columns are nullable additions — existing rows stay valid and no data
 * is rewritten (decision 21: preserve existing data).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('phone', 32)->nullable()->after('medical_notes');
            $table->string('email')->nullable()->after('phone');
            $table->string('emergency_contact_name')->nullable()->after('email');
            $table->string('emergency_contact_phone', 32)->nullable()->after('emergency_contact_name');
            $table->string('emergency_contact_relation', 100)->nullable()->after('emergency_contact_phone');
        });

        Schema::table('guardians', function (Blueprint $table) {
            $table->string('email')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn([
                'phone',
                'email',
                'emergency_contact_name',
                'emergency_contact_phone',
                'emergency_contact_relation',
            ]);
        });

        Schema::table('guardians', function (Blueprint $table) {
            $table->dropColumn('email');
        });
    }
};
