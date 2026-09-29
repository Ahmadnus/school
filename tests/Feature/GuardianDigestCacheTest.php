<?php

namespace Tests\Feature;

use App\Enums\ConversationType;
use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\Conversation;
use App\Models\Grade;
use App\Models\Guardian;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentGuardian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ملخّص وليّ الأمر: كلفةٌ لا تتبع عدد الأبناء، وعدّادٌ لا يتجمّد.
 *
 * كل ابنٍ كان يكلّف سبعةً وعشرين استعلاماً — حضورُ سنته وتقويمُ أسبوعه
 * وإشاراتُه ورسومه. ولا يبطئ ذلك طلباً واحداً، لكنّه يضرب القاعدة بمئاتٍ حين
 * يفتح مئةُ وليّ أمر التطبيق صباحاً معاً، وهو ما رصدته قياسات الحمل: أبطأ
 * مسارٍ تحت الضغط وحده.
 */
class GuardianDigestCacheTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Section $section;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->year = AcademicYear::factory()->current()->create(['school_id' => $this->school->id]);
        $grade = Grade::factory()->create(['school_id' => $this->school->id]);
        $this->section = Section::factory()->create([
            'grade_id' => $grade->id,
            'academic_year_id' => $this->year->id,
        ]);
    }

    /** وليُّ أمرٍ له [$count] من الأبناء. */
    private function guardianWith(int $count): User
    {
        $user = User::factory()->role(UserRole::Guardian)->create(['school_id' => $this->school->id]);
        $guardian = Guardian::factory()->create([
            'school_id' => $this->school->id,
            'user_id' => $user->id,
        ]);

        for ($i = 0; $i < $count; $i++) {
            $child = Student::factory()->create(['school_id' => $this->school->id]);
            StudentEnrollment::factory()->create([
                'student_id' => $child->id,
                'section_id' => $this->section->id,
                'academic_year_id' => $this->year->id,
            ]);
            StudentGuardian::factory()->create([
                'student_id' => $child->id,
                'guardian_id' => $guardian->id,
            ]);
        }

        return $user;
    }

    private function countQueries(callable $call): int
    {
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();
        $call();
        $n = count(DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();

        return $n;
    }

    public function test_opening_the_digest_twice_does_not_repeat_the_heavy_work(): void
    {
        Sanctum::actingAs($this->guardianWith(2));

        $cold = $this->countQueries(fn () => $this->getJson('/api/guardian/digest')->assertOk());
        $warm = $this->countQueries(fn () => $this->getJson('/api/guardian/digest')->assertOk());

        $this->assertLessThan(
            $cold,
            $warm,
            "الفتحة الثانية ({$warm}) لم توفّر شيئاً عن الأولى ({$cold}) — لم يعمل التخزين.",
        );
    }

    public function test_a_third_child_does_not_cost_a_third_of_the_queries_again(): void
    {
        // يُسخَّن كلٌّ منهما أوّلاً، فالمقارنة على الحالة الدائمة لا على البرود.
        Sanctum::actingAs($this->guardianWith(1));
        $this->getJson('/api/guardian/digest')->assertOk();
        $one = $this->countQueries(fn () => $this->getJson('/api/guardian/digest')->assertOk());

        Sanctum::actingAs($this->guardianWith(3));
        $this->getJson('/api/guardian/digest')->assertOk();
        $three = $this->countQueries(fn () => $this->getJson('/api/guardian/digest')->assertOk());

        // ثلاثة أبناء لا يكلّفون ثلاثة أضعاف: الكتلة الثقيلة تُقرأ مرّة واحدة.
        $this->assertLessThan(
            $one * 2,
            $three,
            "ثلاثة أبناء كلّفوا {$three} استعلاماً مقابل {$one} لابنٍ واحد — الكلفة ما زالت تتبع العدد.",
        );
    }

    public function test_the_fast_moving_counters_stay_live(): void
    {
        $user = $this->guardianWith(1);
        Sanctum::actingAs($user);

        $before = $this->getJson('/api/guardian/digest')->assertOk()->json('data');

        // محادثة تُفتح بعد أوّل قراءة: العدّاد الحيّ يجب أن يراها فوراً، فهو
        // خارج ما يُخزَّن.
        $conversation = Conversation::create([
            'school_id' => $this->school->id,
            'type' => ConversationType::Guardians,
            'last_message_at' => now(),
        ]);
        $conversation->participantRecords()->create(['user_id' => $user->id]);

        $after = $this->getJson('/api/guardian/digest')->assertOk()->json('data');

        $this->assertSame(
            $before['open_conversations'] + 1,
            $after['open_conversations'],
            'عدّاد المحادثات تجمّد في الكاش — والمقصود أن يبقى حيّاً.',
        );
    }

    public function test_each_guardian_sees_only_their_own_children(): void
    {
        $mine = $this->guardianWith(2);
        $theirs = $this->guardianWith(1);

        Sanctum::actingAs($mine);
        $a = $this->getJson('/api/guardian/digest')->assertOk()->json('data.children');

        Sanctum::actingAs($theirs);
        $b = $this->getJson('/api/guardian/digest')->assertOk()->json('data.children');

        // مفتاحٌ مشترك بالخطأ يخلط أبناء عائلةٍ بأخرى — وهذا أسوأ من البطء.
        $this->assertCount(2, $a);
        $this->assertCount(1, $b);
        $this->assertEmpty(array_intersect(
            array_column(array_column($a, 'student'), 'id'),
            array_column(array_column($b, 'student'), 'id'),
        ));
    }
}
