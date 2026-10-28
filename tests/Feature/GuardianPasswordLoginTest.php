<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\Guardian;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * دخول وليّ الأمر بحساب وكلمة سر — بديلاً عن الرمز، وعلى الحساب نفسه.
 *
 * الحارس الأهمّ: الأمر التجريبي لا يعمل إلا داخل شعبة «تجربة» ولا يلمس
 * طلاباً حقيقيين.
 */
class GuardianPasswordLoginTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        AcademicYear::factory()->create(['school_id' => $this->school->id, 'is_current' => true]);
    }

    private function trialSection(): Section
    {
        $grade = Grade::factory()->create(['school_id' => $this->school->id]);

        return Section::factory()->create(['grade_id' => $grade->id, 'name' => 'تجربة']);
    }

    public function test_demo_refuses_when_there_is_no_trial_section(): void
    {
        $this->artisan('guardian:account', ['action' => 'demo'])->assertFailed();

        $this->assertSame(0, Guardian::count());
        $this->assertSame(0, Student::count());
    }

    public function test_demo_creates_one_guardian_and_one_student_in_the_trial_section(): void
    {
        $section = $this->trialSection();
        // طالب حقيقي في شعبة أخرى: يجب ألّا يتغيّر ولا يُرى.
        $real = Student::factory()->create(['school_id' => $this->school->id]);

        $this->artisan('guardian:account', ['action' => 'demo', '--password' => 'Secret-123'])->assertSuccessful();

        $guardian = Guardian::where('phone', '0990000001')->firstOrFail();
        $this->assertCount(1, $guardian->students);
        $this->assertSame($section->id, $guardian->students->first()->currentEnrollment->section_id);
        $this->assertNotNull($guardian->fresh()->user_id);
        $this->assertSame(2, Student::count());
        $this->assertFalse($guardian->students->contains($real));
    }

    public function test_the_demo_account_signs_in_by_email_and_by_phone_and_sees_only_its_child(): void
    {
        $this->trialSection();
        $this->artisan('guardian:account', ['action' => 'demo', '--password' => 'Secret-123'])->assertSuccessful();

        foreach (['parent.demo@kader.test', '0990000001'] as $login) {
            $this->postJson('/api/login', ['login' => $login, 'password' => 'Secret-123'])
                ->assertOk()
                ->assertJsonPath('data.role', 'guardian');
        }

        $token = $this->postJson('/api/login', [
            'login' => 'parent.demo@kader.test',
            'password' => 'Secret-123',
        ])->json('token');

        $children = $this->withToken($token)->getJson('/api/guardian/digest')
            ->assertOk()
            ->json('data.children');

        $this->assertCount(1, $children);
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        $this->trialSection();
        $this->artisan('guardian:account', ['action' => 'demo', '--password' => 'Secret-123'])->assertSuccessful();

        $this->postJson('/api/login', ['login' => '0990000001', 'password' => 'nope'])
            ->assertUnprocessable();
    }

    public function test_set_gives_an_existing_guardian_a_password_on_the_same_account(): void
    {
        $guardian = Guardian::factory()->create(['school_id' => $this->school->id, 'phone' => '0955000111']);

        $this->artisan('guardian:account', [
            'action' => 'set', 'phone' => '0955000111', '--password' => 'Another-456',
        ])->assertSuccessful();

        $this->postJson('/api/login', ['login' => '0955000111', 'password' => 'Another-456'])
            ->assertOk();

        // الحساب نفسه الذي يدخل به الرمز: لا نسخة ثانية.
        $this->assertSame(1, $guardian->fresh()->user()->count());
    }
}
