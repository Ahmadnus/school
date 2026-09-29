<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الشكوى نوعٌ ثالث من المحادثة، لا نظامٌ منفصل.
 *
 * فهي خيطٌ له حالة (مفتوحة ← قيد المعالجة ← محلولة) ورسائل ومرفقات وإشعارات
 * وعلمُ متابعة — وهذا كلّه موجودٌ في `conversations` ومُختبَر. ونظامٌ منفصل
 * يعني نسخةً ثانية من كل ذلك تفترق عنها عند أوّل تعديل.
 *
 * وعمودان يكفيان لموضوعها: أستاذٌ بعينه، أو بابٌ من الخدمات. واحدٌ منهما
 * يُملأ لا كلاهما — يحرسه التحقّق في الطلب.
 *
 * والأستاذ المشكوّ عليه **ليس طرفاً** في الخيط: الرؤية في هذا النظام
 * بالمشاركين، فعدم إضافته يمنعه من الرؤية بلا استثناءٍ خاصّ في السياسات —
 * وهذا قرار المدرسة: الشكوى للإدارة وحدها.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL يحفظ `type` كـenum، فتوسيعه يحتاج تعديل العمود. وSQLite
        // (الاختبارات) يحفظه نصّاً بقيدٍ لا يُعدَّل، فيُعاد بناء العمود.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE conversations MODIFY COLUMN type ENUM('guardians','staff','complaints') NOT NULL",
            );
        }

        Schema::table('conversations', function (Blueprint $table) {
            // الأستاذ المشكوّ عليه. `nullOnDelete` لا `cascade`: خروج أستاذٍ من
            // المدرسة لا يمحو شكوى قُدِّمت عليه ولا سجلّ معالجتها.
            $table->foreignId('about_staff_id')
                ->nullable()
                ->after('student_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->string('complaint_category')->nullable()->after('about_staff_id');

            // فرز الإدارة: الشكاوى المفتوحة أوّلاً.
            $table->index(['school_id', 'type', 'status', 'last_message_at'], 'conversations_triage_index');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_triage_index');
            $table->dropConstrainedForeignId('about_staff_id');
            $table->dropColumn('complaint_category');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE conversations MODIFY COLUMN type ENUM('guardians','staff') NOT NULL",
            );
        }
    }
};
