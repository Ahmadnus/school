<?php

namespace Database\Seeders;

use App\Enums\GuardianRelation;
use App\Enums\UserRole;
use App\Models\Guardian;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * حسابات جاهزة للتجربة على نسخة منشورة.
 *
 * `DatabaseSeeder` يبني المدرسة والصفوف والطلاب ويعطي أولياء الأمور أرقاماً
 * عشوائية — فلا يعرف المجرِّب رقماً يدخل به. هذا البذر يثبّت رقماً واحداً
 * معروفاً ويربطه بثلاثة أبناء، فتظهر بيانات حقيقية في تطبيق الأهالي بدل
 * شاشة فارغة.
 *
 *   php artisan db:seed --class=DemoAccountsSeeder --force
 *
 * يعمل مرّة أو مرّات: يبحث قبل أن ينشئ، فإعادة تشغيله لا تُنشئ نسخاً ثانية.
 */
class DemoAccountsSeeder extends Seeder
{
    /** الرقم الذي يدخل به وليّ الأمر في التجربة. */
    private const GUARDIAN_PHONE = '0955556001';

    public function run(): void
    {
        $school = School::query()->firstOrFail();

        // وليّ الأمر: يُبحث بالرقم أولاً كي لا يتكرّر عند إعادة التشغيل.
        $guardian = Guardian::query()
            ->where('school_id', $school->id)
            ->where('phone', self::GUARDIAN_PHONE)
            ->first();

        if (! $guardian) {
            // يُرقّى وليّ أمر قائم بدل إنشاء واحد بلا أبناء: الطلاب مرتبطون
            // به أصلاً من `DatabaseSeeder`، فيرى المجرِّب بيانات لا فراغاً.
            $guardian = Guardian::query()
                ->where('school_id', $school->id)
                ->whereHas('students')
                ->first()
                ?? Guardian::factory()->create(['school_id' => $school->id]);

            $guardian->forceFill([
                'name' => 'وليّ التجربة',
                'phone' => self::GUARDIAN_PHONE,
            ])->save();
        }

        // ثلاثة أبناء تحته: رقم واحد يفتح على أكثر من ابن يُظهر التبديل بينهم.
        $linked = $guardian->students()->pluck('students.id');

        if ($linked->count() < 3) {
            Student::query()
                ->where('school_id', $school->id)
                ->whereNotIn('id', $linked->all() ?: [0])
                ->limit(3 - $linked->count())
                ->get()
                ->each(function (Student $student) use ($guardian) {
                    $student->guardianLinks()->create([
                        'guardian_id' => $guardian->id,
                        'relation' => GuardianRelation::Father,
                        'is_primary' => $student->guardianLinks()->count() === 0,
                    ]);
                });
        }

        $admin = User::query()->where('email', 'admin@example.test')->first();

        $this->command?->newLine();
        $this->command?->info('حسابات التجربة جاهزة:');
        $this->command?->line('  الكادر    : '.($admin?->email ?? 'admin@example.test').' / password');
        $this->command?->line('  الأهالي   : '.self::GUARDIAN_PHONE.'  (رمز تحقّق، بلا كلمة سر)');
        $this->command?->line('  الأبناء   : '.$guardian->fresh()->students()->count());
        $this->command?->newLine();
    }
}
