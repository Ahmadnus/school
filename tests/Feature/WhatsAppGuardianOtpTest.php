<?php

namespace Tests\Feature;

use App\Models\Guardian;
use App\Models\School;
use App\Services\WhatsAppSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * رمز الدخول يصل على واتساب عبر بوابة WAHA — وإلى رقمٍ مسجَّل فقط.
 */
class WhatsAppGuardianOtpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.whatsapp.url', 'http://waha.test');
        config()->set('services.whatsapp.key', 'secret');
        config()->set('services.whatsapp.session', 'default');
        config()->set('services.guardian_auth.expose_code', true);

        Http::fake(['waha.test/*' => Http::response(['id' => 'x'])]);
    }

    public function test_a_registered_guardian_receives_the_code_on_whatsapp(): void
    {
        $school = School::factory()->create(['phone_country_code' => '963']);
        Guardian::factory()->create(['school_id' => $school->id, 'phone' => '0955556001']);

        $code = $this->postJson('/api/guardian/request-code', ['phone' => '0955556001'])
            ->assertOk()
            ->json('data.code');

        Http::assertSent(fn (Request $request) => $request->url() === 'http://waha.test/api/sendText'
            && $request->hasHeader('X-Api-Key', 'secret')
            && $request['session'] === 'default'
            && $request['chatId'] === '963955556001@c.us'
            && str_contains($request['text'], $code));
    }

    public function test_an_unknown_number_sends_nothing(): void
    {
        $this->postJson('/api/guardian/request-code', ['phone' => '0955556009'])->assertOk();

        Http::assertNothingSent();
    }

    public function test_nothing_is_sent_while_the_gateway_is_unset(): void
    {
        config()->set('services.whatsapp.url', null);

        $school = School::factory()->create();
        Guardian::factory()->create(['school_id' => $school->id, 'phone' => '0955556001']);

        $this->postJson('/api/guardian/request-code', ['phone' => '0955556001'])->assertOk();

        Http::assertNothingSent();
    }

    public function test_the_code_is_written_in_the_apps_language_with_the_school_name(): void
    {
        // الخادم بالإنجليزية افتراضاً؛ التطبيق يطلب العربية.
        config()->set('app.locale', 'en');
        $school = School::factory()->create(['name' => 'المعهد السوري', 'phone_country_code' => '963']);
        Guardian::factory()->create(['school_id' => $school->id, 'phone' => '0955556001']);

        $this->withHeader('X-Locale', 'ar')
            ->postJson('/api/guardian/request-code', ['phone' => '0955556001'])
            ->assertOk();

        Http::assertSent(fn (Request $request) => str_contains($request['text'], 'رمز التحقق')
            && str_contains($request['text'], 'المعهد السوري'));
    }

    public function test_chat_ids_for_local_and_international_numbers(): void
    {
        $this->assertSame('963955123456@c.us', WhatsAppSender::chatIdFor('0955123456', '963'));
        $this->assertSame('963955123456@c.us', WhatsAppSender::chatIdFor('+963 955 123 456', '962'));
        $this->assertSame('962791234567@c.us', WhatsAppSender::chatIdFor('00962791234567', '963'));
        $this->assertNull(WhatsAppSender::chatIdFor('abc', '963'));
    }
}
