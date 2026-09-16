<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تحصين الدفعات: إيصال مرقّم لا يُحذف، توزيع صريح على الأقساط، وحماية من
 * التسجيل المزدوج.
 *
 * قبل هذا: الدفعة تُحذف نهائياً فلا يبقى أثر لمبلغ دخل الصندوق؛ وترتبط بقسط
 * واحد فقط فلا يمكن لدفعة أن تغطّي قسطين؛ وضغطة مزدوجة في التطبيق تسجّلها
 * مرتين.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_receipt_counters', function (Blueprint $table) {
            $table->foreignId('school_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
        });

        Schema::table('fee_payments', function (Blueprint $table) {
            // منسوخ من الطالب: ترقيم الإيصالات والتقارير المالية كلها بمستوى المدرسة.
            $table->foreignId('school_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('receipt_number')->nullable()->after('school_id');
            $table->string('method', 20)->default('cash')->after('amount_minor');
            // مفتاح يرسله التطبيق مع الطلب: إعادة الإرسال تعيد الدفعة نفسها
            // بدل أن تنشئ ثانية.
            $table->string('idempotency_key', 64)->nullable()->after('reference');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();

            $table->unique(['school_id', 'receipt_number']);
            $table->unique(['school_id', 'idempotency_key']);
            $table->index(['school_id', 'paid_on']);
        });

        // توزيع الدفعة على الأقساط. صف بلا installment_id = دفعة مقدّمة لم
        // تُنسب بعد إلى قسط.
        Schema::create('fee_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_plan_installment_id')->nullable()
                ->constrained('fee_plan_installments')->cascadeOnDelete();
            $table->bigInteger('amount_minor');
            $table->timestamps();

            $table->index(['fee_plan_installment_id']);
        });

        $this->backfill();

        Schema::table('fee_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('installment_id');
        });

        Schema::table('fee_plans', function (Blueprint $table) {
            // يظهر في قائمة الخصومات حتى يُعرف لماذا خُصم.
            $table->string('discount_reason', 300)->nullable()->after('discount_minor');
        });
    }

    /** نقل الدفعات القائمة إلى الشكل الجديد بلا فقد أي مبلغ. */
    private function backfill(): void
    {
        $counters = [];

        $rows = DB::table('fee_payments')
            ->join('fee_plans', 'fee_plans.id', '=', 'fee_payments.fee_plan_id')
            ->join('students', 'students.id', '=', 'fee_plans.student_id')
            ->orderBy('fee_payments.id')
            ->get(['fee_payments.id', 'fee_payments.installment_id', 'fee_payments.amount_minor', 'students.school_id']);

        foreach ($rows as $row) {
            $schoolId = (int) $row->school_id;
            $counters[$schoolId] = ($counters[$schoolId] ?? 0) + 1;

            DB::table('fee_payments')->where('id', $row->id)->update([
                'school_id' => $schoolId,
                'receipt_number' => $counters[$schoolId],
            ]);

            DB::table('fee_payment_allocations')->insert([
                'fee_payment_id' => $row->id,
                'fee_plan_installment_id' => $row->installment_id,
                'amount_minor' => $row->amount_minor,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($counters as $schoolId => $used) {
            DB::table('fee_receipt_counters')->insert([
                'school_id' => $schoolId,
                'next_number' => $used + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('fee_plans', fn (Blueprint $table) => $table->dropColumn('discount_reason'));

        Schema::table('fee_payments', function (Blueprint $table) {
            $table->foreignId('installment_id')->nullable()
                ->constrained('fee_plan_installments')->nullOnDelete();
        });

        Schema::dropIfExists('fee_payment_allocations');

        Schema::table('fee_payments', function (Blueprint $table) {
            $table->dropUnique(['school_id', 'receipt_number']);
            $table->dropUnique(['school_id', 'idempotency_key']);
            $table->dropIndex(['school_id', 'paid_on']);
            $table->dropConstrainedForeignId('school_id');
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['receipt_number', 'method', 'idempotency_key', 'voided_at', 'void_reason']);
        });

        Schema::dropIfExists('fee_receipt_counters');
    }
};
