<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * School-level configuration so one installation adapts to different
 * schools without code changes: contact details for the profile/contacts
 * screens, and the academic/attendance defaults that used to be constants.
 *
 * Additive and defaulted — existing rows keep working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('phone', 32)->nullable()->after('email');
            $table->text('address')->nullable()->after('phone');
            $table->string('website')->nullable()->after('address');
            $table->unsignedSmallInteger('absence_warning_threshold')->default(10)->after('currency');
            $table->decimal('default_pass_score', 6, 2)->default(50)->after('absence_warning_threshold');
            $table->decimal('default_max_score', 6, 2)->default(100)->after('default_pass_score');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn([
                'phone',
                'address',
                'website',
                'absence_warning_threshold',
                'default_pass_score',
                'default_max_score',
            ]);
        });
    }
};
