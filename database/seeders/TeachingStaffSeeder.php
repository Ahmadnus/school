<?php

namespace Database\Seeders;

use App\Enums\Status;
use App\Enums\UserRole;
use App\Enums\Weekday;
use App\Models\School;
use App\Models\SchoolDayHours;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\TeacherAvailability;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * كادر تعليمي جاهز لتوليد الجدول: أساتذة، وإسنادات، وأوقات فراغ.
 *
 * مولّد الجدول لا يخترع شيئاً — يقرأ ثلاثة أشياء ويرضيها: دوام المدرسة، ومن
 * يدرّس ماذا لأيّ شعبة، ومتى يكون كلّ أستاذ متفرّغاً. وبلا هذه الثلاثة يقول
 * «لا مطالب» ويخرج بجدولٍ فارغ. فهذا البذر يبنيها على بيانات المدرسة القائمة
 * (صفوفها وشعبها وموادّها) بدل أن ينشئ مدرسةً ثانية.
 *
 *   php artisan db:seed --class=TeachingStaffSeeder --force
 *
 * **يعمل مرّة أو مرّات.** كل شيء يُبحَث قبل أن يُنشأ، فإعادة التشغيل لا
 * تُنشئ أستاذاً ثانياً بالاسم نفسه ولا تُضاعف الإسنادات. وأوقات الفراغ
 * تُستبدَل لا تُكدَّس: أستاذٌ بصفّين متداخلين لليوم نفسه يُربك المولّد.
 *
 * **العشوائيّة مبذورة (seeded).** الأوقات «عشوائيّة» كما طُلبت، لكن بذرة
 * ثابتة: نفس التشغيل يعطي نفس الأوقات. عشوائيّةٌ حقيقيّة تجعل جدول اليوم
 * مختلفاً عن جدول أمس بلا سبب، فلا يُعرَف هل تغيّر الجدول لأن الكود تغيّر أم
 * لأن الحظّ تغيّر.
 */
class TeachingStaffSeeder extends Seeder
{
    /** دوام المدرسة: كل أيام الأسبوع 11:00–18:00، والسبت 10:00–16:00. */
    private const OPEN_FROM = '11:00';

    private const OPEN_TO = '18:00';

    private const SATURDAY_FROM = '10:00';

    private const SATURDAY_TO = '16:00';

    /**
     * أسماء الأساتذة — واحدٌ لكل مادّة في الصفّ.
     *
     * تُوزَّع بالترتيب لا بالقرعة، فيبقى «أ. سامر» أستاذَ المادّة نفسها في كل
     * تشغيل، ويصير الجدول المولَّد قابلاً للمقارنة بما قبله.
     *
     * @var list<array{0:string,1:string}>
     */
    private const NAMES = [
        ['سامر', 'الخطيب'],
        ['هيثم', 'العبد'],
        ['ريم', 'حدّاد'],
        ['منال', 'السبع'],
        ['فادي', 'شحادة'],
        ['غادة', 'الأحمد'],
        ['نبيل', 'قاسم'],
        ['لينا', 'مرعي'],
        ['وسيم', 'الحلبي'],
        ['رولا', 'ديب'],
        ['أيمن', 'الجاسم'],
        ['سهير', 'الزين'],
        ['ماهر', 'العلي'],
        ['نور', 'الشامي'],
        ['بشار', 'الحمصي'],
        ['عبير', 'صالح'],
    ];

    /** بذرة ثابتة: «عشوائيّ» يعني متنوّعاً، لا متغيّراً كل تشغيل. */
    private const SEED = 20260927;

    /**
     * أقصى عدد حصص أسبوعيّة لأستاذٍ واحد.
     *
     * ليس رقماً تجميليّاً: أستاذٌ عليه ٢١ حصّة وفراغه ٣٠ ساعة يبدو ممكناً على
     * الورق، لكن كل حصّة تزاحم شعبةً وأستاذاً آخر على الساعة نفسها، فيطول
     * تراجُع المولّد حتى تنفد ميزانيّة خطواته ويُعلن عجزاً ليس في البيانات.
     * فإن زاد حِمل المادّة عن هذا الحدّ، تُقسَم صفوفها على أستاذٍ ثانٍ — وهذا
     * ما تفعله المدرسة نفسها.
     */
    private const MAX_LESSONS = 18;

    /** أيّام عمل الأستاذ من السبعة — ستّة، ليبقى له يومُ راحة. */
    private const WORKING_DAYS = 6;

    public function run(): void
    {
        $school = School::query()->firstOrFail();

        mt_srand(self::SEED);

        $this->setSchoolHours($school);

        $subjects = Subject::query()
            ->whereHas('grade', fn ($q) => $q->where('school_id', $school->id))
            ->with('grade')
            ->orderBy('grade_id')
            ->orderBy('name')
            ->get();

        if ($subjects->isEmpty()) {
            $this->command?->warn('لا موادّ في المدرسة — لا شيء يُسنَد.');

            return;
        }

        // مادّة واحدة باسمٍ واحد قد تتكرّر في صفوف عدّة (لغة عربية في التاسع
        // وفي العلمي). يأخذها أستاذٌ واحد: هكذا هي في المدرسة فعلاً، وهكذا
        // يصير للمولّد قيدٌ حقيقي يرضيه — أستاذٌ لا يكون في شعبتين معاً.
        $byName = $subjects->groupBy(fn (Subject $s) => $this->familyOf($s->name));

        $orphans = $subjects->whereNull('term_id')->count();

        if ($orphans > 0) {
            // مادّة بلا فصل لا يراها المولّد أصلاً، فيقول «لا مطالب» وكأنّ
            // الإسناد لم يحدث. تُقال الحقيقة الآن لا بعد جدولٍ فارغ.
            $this->command?->warn("{$orphans} مادّة بلا فصل دراسي — لن تدخل الجدول.");
        }

        $index = 0;
        $teachers = [];

        foreach ($byName as $familySubjects) {
            $teacher = $this->teacherFor($school, $index++);
            $teachers[] = $teacher;
            $load = 0;

            foreach ($familySubjects as $subject) {
                $lessons = $this->weeklyLoadOf($subject);

                // الحدّ يُفحَص قبل الإسناد لا بعده: أستاذٌ ثالثٌ للمادّة أرحم
                // من جدولٍ يُعلن عجزه.
                if ($load > 0 && $load + $lessons > self::MAX_LESSONS) {
                    $teacher = $this->teacherFor($school, $index++);
                    $teachers[] = $teacher;
                    $load = 0;
                }

                $this->assignAllSections($teacher, $subject);
                $load += $lessons;
            }
        }

        foreach ($teachers as $teacher) {
            $this->randomAvailability($teacher);
        }

        $this->command?->info(sprintf(
            'أساتذة: %d · إسنادات: %d · أوقات فراغ: %d',
            count($teachers),
            TeacherAssignment::query()->whereIn('staff_id', collect($teachers)->pluck('id'))->count(),
            TeacherAvailability::query()->whereIn('staff_id', collect($teachers)->pluck('id'))->count(),
        ));
    }

    /** دوام المدرسة لكل أيّام الأسبوع، والسبت وحده أقصر. */
    private function setSchoolHours(School $school): void
    {
        foreach (Weekday::cases() as $day) {
            $saturday = $day === Weekday::Saturday;

            // updateOrCreate لا create: البذر يُعاد تشغيله، ويومٌ بسجلَّي دوام
            // يجعل المولّد يرى نافذتين متضاربتين لليوم نفسه.
            SchoolDayHours::query()->updateOrCreate(
                [
                    'school_id' => $school->id,
                    'day_of_week' => $day,
                ],
                [
                    'starts_at' => $saturday ? self::SATURDAY_FROM : self::OPEN_FROM,
                    'ends_at' => $saturday ? self::SATURDAY_TO : self::OPEN_TO,
                ],
            );
        }
    }

    /**
     * اسم المادّة بلا لاحقة الصفّ: «لغة عربية — تاسع» و«لغة عربية — علمي»
     * مادّةٌ واحدة يدرّسها أستاذٌ واحد.
     */
    private function familyOf(string $name): string
    {
        return trim(preg_split('/\s+[—–-]\s+/u', $name)[0] ?? $name);
    }

    /** أستاذٌ بالاسم الثابت، يُبحَث قبل أن يُنشأ. */
    private function teacherFor(School $school, int $index): User
    {
        [$first, $last] = self::NAMES[$index % count(self::NAMES)];

        // من نفد اسمه يأخذ رقماً، فلا يتصادم بريدان.
        $suffix = intdiv($index, count(self::NAMES));
        $email = sprintf('teacher%d@kader.local', $index + 1);

        $teacher = User::query()->where('email', $email)->first();

        if ($teacher) {
            return $teacher;
        }

        return User::query()->create([
            'school_id' => $school->id,
            'first_name' => $first,
            'last_name' => $suffix > 0 ? $last.' '.($suffix + 1) : $last,
            'email' => $email,
            'password' => Hash::make('Teacher#'.($index + 1).'2026'),
            'role' => UserRole::Teacher,
            'status' => Status::Active,
        ]);
    }

    /** حصص المادّة الأسبوعيّة في كل شعب صفّها مجموعةً. */
    private function weeklyLoadOf(Subject $subject): int
    {
        $sections = Section::query()->where('grade_id', $subject->grade_id)->count();

        return $sections * max(1, (int) $subject->periods_per_week);
    }

    /** الأستاذ يدرّس المادّة في كل شعب صفّها. */
    private function assignAllSections(User $teacher, Subject $subject): void
    {
        $sections = Section::query()
            ->where('grade_id', $subject->grade_id)
            ->get();

        foreach ($sections as $section) {
            TeacherAssignment::query()->updateOrCreate(
                [
                    'staff_id' => $teacher->id,
                    'subject_id' => $subject->id,
                    'section_id' => $section->id,
                ],
                // null يعني «خُذ ما على المادّة» — فيبقى عدد الحصص مصدرَه
                // الوحيد: المادّة نفسها.
                ['lessons_per_week' => null],
            );
        }
    }

    /**
     * أوقات فراغٍ عشوائيّة داخل دوام المدرسة.
     *
     * لكلّ أستاذ ستّة أيّام من السبعة، وفي كل يوم نافذةٌ تبدأ بعد الافتتاح
     * بساعةٍ على الأكثر وتنتهي قبل الإغلاق بساعةٍ على الأكثر. النافذة لا تقلّ
     * عن ثلاث ساعات: أستاذٌ متفرّغ ساعةً واحدة في الأسبوع يجعل المولّد يُعلن
     * العجز بحقّ، فلا يُعرَف هل القيد صحيح أم البيانات هزيلة.
     */
    private function randomAvailability(User $teacher): void
    {
        // تُمحى أوّلاً: إعادة التشغيل تُبدل الأوقات ولا تُكدّس نوافذ متداخلة
        // لليوم نفسه.
        TeacherAvailability::query()->where('staff_id', $teacher->id)->delete();

        $days = Weekday::cases();
        shuffle($days);
        $working = array_slice($days, 0, self::WORKING_DAYS);

        foreach ($working as $day) {
            $saturday = $day === Weekday::Saturday;
            $open = $saturday ? self::SATURDAY_FROM : self::OPEN_FROM;
            $close = $saturday ? self::SATURDAY_TO : self::OPEN_TO;

            $openMinutes = $this->minutes($open);
            $closeMinutes = $this->minutes($close);

            $from = $openMinutes + 60 * mt_rand(0, 1);
            $to = $closeMinutes - 60 * mt_rand(0, 1);

            if ($to - $from < 180) {
                $from = $openMinutes;
                $to = $closeMinutes;
            }

            TeacherAvailability::query()->create([
                'staff_id' => $teacher->id,
                'day_of_week' => $day,
                'starts_at' => $this->clock($from),
                'ends_at' => $this->clock($to),
            ]);
        }
    }

    private function minutes(string $clock): int
    {
        [$h, $m] = array_map('intval', explode(':', $clock));

        return $h * 60 + $m;
    }

    private function clock(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
