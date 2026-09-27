<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * التسميع كنوع تقييم، لا كنظام درجات ثانٍ.
 *
 * الحاجة: تسميعٌ يشترك فيه بعض طلاب الشعبة لا كلّهم، ولا يُنزِل علامةَ من لم
 * يشترك. وهذا **هو** سلوك `grades_scores` أصلاً: لا صفّ = لا درجة، والأنواع
 * التي لا درجة فيها تُستثنى من الحساب في `SubjectGrade`. فلا جدولَ جديداً ولا
 * حساباً جديداً: التسميع نوعٌ في `assessment_types`، وكل جلسة تسميع تقييمٌ
 * واحد، وصفوف الدرجات للمشاركين وحدهم.
 *
 * الناقص في `assessments` شيئان:
 *
 *  - **الشعبة.** التقييم اليوم مرتبط بالمادة، والمادة للصفّ كلّه. وتسميع
 *    «التاسع أ» ليس تسميع «التاسع ب» وهما يتقاسمان المادة نفسها.
 *  - **من أنشأه.** الأستاذ الذي سمّع. `grades_scores.entered_by` يقول من كتب
 *    الدرجة، لا من أقام الجلسة، وهما قد يفترقان.
 *
 * وكلاهما **قابل للإفراغ**: التقييمات القائمة في الإنتاج تقييمات صفٍّ كامل،
 * فتبقى كما هي بلا شعبة، ولا تُلمَس.
 *
 * ولم يُمسّ `unique(subject_id, name)`. إضعافه إلى
 * `unique(subject_id, section_id, name)` كان يبدو الحلّ الطبيعي، لكن MySQL
 * يعدّ كل `NULL` مختلفاً عن أخيه، فتصير التقييمات القائمة (شعبتها `NULL`)
 * بلا أيّ حارس ضدّ التكرار — نخسر ضماناً قائماً لنكسب مرونةً لا نحتاجها.
 * فاسم جلسة التسميع يُبنى في الخادم من الشعبة والتاريخ، ويصير الفهرس القائم
 * هو نفسه ما يمنع تسميعاً مكرّراً لشعبةٍ في يومٍ في مادة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->foreignId('section_id')->nullable()->after('subject_id')
                ->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->after('held_on')
                ->constrained('users')->nullOnDelete();

            $table->index(['section_id', 'assessment_type_id']);
        });
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropIndex(['section_id', 'assessment_type_id']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('section_id');
        });
    }
};
