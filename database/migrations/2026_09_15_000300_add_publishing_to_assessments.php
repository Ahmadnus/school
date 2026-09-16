<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نشر صريح للعلامات، على غرار «تسليم الحضور».
 *
 * السبب: شبكة إدخال العلامات تُحفظ مرات وهي ناقصة — الأستاذ يدخل نصف الصف ثم
 * يكمل غداً ثم يصحّح خطأً. لو أشعر كل حفظ لوصل الأهل إشعار عن علامة لم تكتمل
 * ثم إشعار ثانٍ يناقضه. النشر يفصل «أعمل عليها» عن «صارت رسمية»، ويصير له
 * وقت واحد معروف ومسؤول معروف.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('weight_percent');
            $table->foreignId('published_by')->nullable()->after('published_at')
                ->constrained('users')->nullOnDelete();
            // تاريخ الامتحان: يُعلن للأهل مسبقاً، ويرتّب شريط التقييمات.
            $table->date('held_on')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('published_by');
            $table->dropColumn(['published_at', 'held_on']);
        });
    }
};
