<?php

namespace App\Console\Commands;

use App\Enums\GuardianRelation;
use App\Models\AcademicYear;
use App\Models\Guardian;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Services\GuardianAccount;
use App\Support\PhoneLookup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * دخول وليّ الأمر بحساب وكلمة سر — إلى جانب الرمز.
 *
 * الدخول بكلمة السر هو `POST /api/login` نفسه (بريد أو هاتف + كلمة سر)، فلا
 * مسار جديد: كل ما يلزم أن يكون لحساب وليّ الأمر كلمة سرٍّ معروفة. وهذا الأمر
 * يضعها. والحساب هو ذاته الذي يدخل به الرمز (`users` ← `guardians.user_id`)،
 * فالمنشورات والإشعارات ورسائل واتساب تصله كما تصل لأيّ وليّ أمر.
 *
 * ```
 * php artisan guardian:account set 0912345678 --password=secret
 * php artisan guardian:account demo            # حساب + طالب في شعبة «تجربة»
 * ```
 *
 * **حارس البيانات الحقيقية**: `demo` لا يُنشئ إلا طالباً ووليّاً جديدين داخل
 * شعبة اسمها «تجربة» — فإن لم توجد الشعبة توقّف ولم يلمس شيئاً. ولا يرسل
 * شيئاً لأحد؛ الإرسال يحدث فقط حين يُنشئ الكادر منشوراً أو إشعاراً.
 */
class GuardianAccountCommand extends Command
{
    protected $signature = 'guardian:account
        {action : set (يضع كلمة سر لوليّ أمر موجود) | demo (حساب تجريبي في شعبة تجربة)}
        {phone? : هاتف وليّ الأمر (لـ set)، أو هاتف الحساب التجريبي (لـ demo)}
        {--password= : كلمة السر؛ تُولَّد عشوائياً إن تُركت}
        {--email=parent.demo@kader.test : بريد الحساب التجريبي}
        {--section=تجربة : اسم شعبة التجارب}';

    protected $description = 'حساب وكلمة سر لوليّ أمر (دخول بديل عن الرمز)، أو حساب تجريبي في شعبة تجربة';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'set' => $this->setPassword(),
            'demo' => $this->demo(),
            default => $this->fail('الإجراء: set أو demo.'),
        };
    }

    private function setPassword(): int
    {
        $phone = (string) $this->argument('phone');
        if ($phone === '') {
            return $this->fail('أدخل هاتف وليّ الأمر.');
        }

        $guardian = Guardian::query()
            ->whereIn('phone', PhoneLookup::candidates($phone))
            ->first();

        if ($guardian === null) {
            return $this->fail('لا يوجد وليّ أمر بهذا الهاتف.');
        }

        $user = GuardianAccount::for($guardian);
        if ($user === null) {
            return $this->fail('وليّ الأمر بلا هاتف فلا يمكن إنشاء حساب له.');
        }

        $password = $this->option('password') ?: Str::password(12, symbols: false);
        $user->forceFill(['password' => Hash::make($password)])->save();

        $this->info("تم. الدخول: {$user->phone} / {$password}");

        return self::SUCCESS;
    }

    private function demo(): int
    {
        $sections = Section::query()
            ->where('name', $this->option('section'))
            ->whereHas('grade')
            ->get();

        if ($sections->count() !== 1) {
            return $this->fail(
                $sections->isEmpty()
                    ? 'لا توجد شعبة بهذا الاسم — لم يُنشأ شيء.'
                    : 'أكثر من شعبة بهذا الاسم — حدّد واحدة بـ --section فريد. لم يُنشأ شيء.'
            );
        }

        /** @var Section $section */
        $section = $sections->first();
        $schoolId = $section->grade->school_id;
        $year = AcademicYear::currentFor($schoolId);
        if ($year === null) {
            return $this->fail('لا توجد سنة دراسية حالية.');
        }

        $email = (string) $this->option('email');
        $phone = $this->argument('phone') ?: '0990000001';
        $password = $this->option('password') ?: Str::password(12, symbols: false);

        // الهاتف أو البريد مأخوذان بحساب غير تجريبي: لا نصادر حساب أحد.
        // البريد فريد على مستوى النظام كلّه، والهاتف داخل المدرسة.
        $taken = User::query()
            ->where(fn ($q) => $q->where('email', $email)
                ->orWhere(fn ($q) => $q->where('school_id', $schoolId)->where('phone', $phone)))
            ->first();
        $reuse = $taken !== null && $taken->email === $email && Guardian::where('user_id', $taken->id)->exists();
        if ($taken !== null && ! $reuse) {
            return $this->fail('البريد أو الهاتف مستعمل لحساب آخر — اختر غيره.');
        }

        DB::transaction(function () use ($section, $schoolId, $year, $email, $phone, $password) {
            $guardian = Guardian::query()->firstOrCreate(
                ['school_id' => $schoolId, 'phone' => $phone],
                ['name' => 'وليّ أمر تجريبي'],
            );

            $user = GuardianAccount::for($guardian);
            $user->forceFill([
                'email' => $email,
                'password' => Hash::make($password),
            ])->save();

            if (! $guardian->students()->exists()) {
                $student = Student::create([
                    'school_id' => $schoolId,
                    'first_name' => 'طالب',
                    'last_name' => 'تجربة',
                ]);

                StudentEnrollment::create([
                    'student_id' => $student->id,
                    'section_id' => $section->id,
                    'academic_year_id' => $year->id,
                    'enrolled_at' => now()->toDateString(),
                ]);

                $student->guardianLinks()->create([
                    'guardian_id' => $guardian->id,
                    'relation' => GuardianRelation::Father,
                    'is_primary' => true,
                ]);
            }
        });

        $this->info("الحساب التجريبي (شعبة {$section->name}):");
        $this->line("  البريد : {$email}");
        $this->line("  الهاتف : {$phone}");
        $this->line("  كلمة السر: {$password}");

        return self::SUCCESS;
    }
}
