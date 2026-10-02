<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Guardian;
use App\Models\School;
use App\Models\User;
use App\Support\PhoneLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * الأب يكتب رقمه كما يحفظه، لا كما سجّلته المدرسة — ويصله الرمز على أيّ حال.
 */
class PhoneFormatSignInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $school = School::factory()->create(['phone_country_code' => '963']);
        Guardian::factory()->create(['school_id' => $school->id, 'phone' => '0944123456']);
        config()->set('services.guardian_auth.expose_code', true);
    }

    /** @return array<string, array{string}> */
    public static function spellings(): array
    {
        return [
            'as registered' => ['0944123456'],
            'international' => ['+963944123456'],
            'double zero' => ['00963944123456'],
            'with spaces' => ['+963 944 123 456'],
            'without the zero' => ['944123456'],
        ];
    }

    #[DataProvider('spellings')]
    public function test_any_spelling_signs_in(string $typed): void
    {
        $code = $this->postJson('/api/guardian/request-code', ['phone' => $typed])
            ->assertOk()
            ->json('data.code');

        $this->assertNotNull($code, "no code issued for {$typed}");

        $this->postJson('/api/guardian/verify-code', ['phone' => $typed, 'code' => $code])
            ->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function test_requesting_in_one_spelling_and_verifying_in_another_works(): void
    {
        $code = $this->postJson('/api/guardian/request-code', ['phone' => '+963944123456'])->json('data.code');

        $this->postJson('/api/guardian/verify-code', ['phone' => '0944123456', 'code' => $code])
            ->assertOk();
    }

    public function test_a_different_number_never_matches(): void
    {
        $this->postJson('/api/guardian/request-code', ['phone' => '0944123457'])
            ->assertOk()
            ->assertJsonMissingPath('data.code');
    }

    public function test_staff_password_login_accepts_the_international_spelling(): void
    {
        User::factory()->role(UserRole::Teacher)->create([
            'school_id' => School::query()->value('id'),
            'phone' => '0933000111',
            'password' => 'secret123',
        ]);

        $this->postJson('/api/login', ['login' => '+963933000111', 'password' => 'secret123'])
            ->assertOk();
    }

    public function test_an_email_is_left_alone(): void
    {
        $this->assertSame(['a@b.test'], PhoneLookup::candidates('a@b.test'));
    }
}
