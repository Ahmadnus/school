<?php

namespace Tests\Feature;

use App\Enums\Status;
use App\Enums\UserRole;
use App\Models\School;
use App\Models\StaffOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * دخول الكادر برمز واتساب: يصل لحسابٍ فعّال من الكادر وحده، ويُستبدل بتوكن.
 */
class StaffWhatsAppLoginTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.whatsapp.url', 'http://waha.test');
        config()->set('services.whatsapp.key', 'secret');

        Http::fake(['waha.test/*' => Http::response(['id' => 'x'])]);

        $this->school = School::factory()->create(['phone_country_code' => '963']);
    }

    private function staff(array $attributes = []): User
    {
        return User::factory()->create([
            'school_id' => $this->school->id,
            'role' => UserRole::Teacher,
            'phone' => '0955556001',
            ...$attributes,
        ]);
    }

    /** The code as it went out on WhatsApp. */
    private function sentCode(): string
    {
        $text = Http::recorded()->last()[0]['text'];
        preg_match('/\d{6}/', $text, $m);

        return $m[0];
    }

    public function test_a_teacher_signs_in_with_the_whatsapp_code(): void
    {
        $this->staff();

        $this->postJson('/api/staff/request-code', ['phone' => '0955556001'])->assertOk();

        Http::assertSent(fn (Request $request) => $request['chatId'] === '963955556001@c.us');

        $this->postJson('/api/staff/verify-code', [
            'phone' => '0955556001',
            'code' => $this->sentCode(),
        ])->assertOk()->assertJsonStructure(['token', 'data']);
    }

    public function test_a_wrong_code_is_refused_and_counted(): void
    {
        $this->staff();

        $this->postJson('/api/staff/request-code', ['phone' => '0955556001']);
        $wrong = $this->sentCode() === '000000' ? '111111' : '000000';

        $this->postJson('/api/staff/verify-code', ['phone' => '0955556001', 'code' => $wrong])
            ->assertUnprocessable();

        $this->assertSame(1, StaffOtp::query()->first()->attempts);
    }

    public function test_a_guardian_account_gets_nothing(): void
    {
        $this->staff(['role' => UserRole::Guardian]);

        $this->postJson('/api/staff/request-code', ['phone' => '0955556001'])->assertOk();

        Http::assertNothingSent();
    }

    public function test_an_inactive_account_gets_nothing(): void
    {
        $this->staff(['status' => Status::Inactive]);

        $this->postJson('/api/staff/request-code', ['phone' => '0955556001'])->assertOk();

        Http::assertNothingSent();
    }

    public function test_an_unknown_number_gets_the_same_reply_and_nothing_sent(): void
    {
        $this->postJson('/api/staff/request-code', ['phone' => '0955556009'])
            ->assertOk()
            ->assertJsonMissingPath('data');

        Http::assertNothingSent();
    }
}
