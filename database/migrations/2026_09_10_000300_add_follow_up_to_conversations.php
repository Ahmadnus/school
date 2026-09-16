<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns a thread into a piece of work the office can track: flag it for
 * follow-up on a date, mark it important, keep a short note about why.
 * Staff-only fields; the guardian app never sees or sets them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->boolean('needs_follow_up')->default(false)->after('status');
            $table->date('follow_up_at')->nullable()->after('needs_follow_up');
            $table->boolean('is_important')->default(false)->after('follow_up_at');
            $table->string('follow_up_note', 500)->nullable()->after('is_important');
            $table->foreignId('flagged_by')->nullable()->after('follow_up_note')
                ->constrained('users')->nullOnDelete();

            $table->index(['school_id', 'needs_follow_up', 'follow_up_at']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['school_id', 'needs_follow_up', 'follow_up_at']);
            $table->dropConstrainedForeignId('flagged_by');
            $table->dropColumn(['needs_follow_up', 'follow_up_at', 'is_important', 'follow_up_note']);
        });
    }
};
