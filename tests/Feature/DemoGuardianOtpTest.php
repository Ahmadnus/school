<?php

namespace Tests\Feature;

use App\Models\Guardian;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * رقم تجريبي واحد برمزٍ ثابت — وما عداه يبقى عشوائيّاً.
 *
 * بلا مزوّد رسائل لا يصل رمز إلى أحد، فيتعذّر عرض تطبيق الأهالي أصلاً.
 * والخطر المقابل أن يصير الرمز الثابت باباً عامّاً: هذه الاختبارات تحرس
 * الحدّ — رقمٌ واحد بالمطابقة التامّة، وإعدادٌ ناقص لا يفتح شيئاً.
 */
class DemoGuardianOtpTest extends TestCase
{
    use RefreshDatabase;

    private const DEMO = '0955556001';

    private const OTHER = '0955556002';

    protected function setUp(): void
    {
        parent::setUp();

        $school = School::factory()->create();

        foreach ([self::DEMO, self::OTHER] as $phone) {
            Guardian::factory()->create(['school_id' => $school->id, 'phone' => $phone]);
        }

        config()->set('services.guardian_auth.expose_code', true);
    }

    private function request(string $phone): ?string
    {
        return $this->postJson('/api/guardian/request-code', ['phone' => $phone])
            ->assertOk()
            ->json('data.code');
    }

    public function test_the_demo_number_gets_the_fixed_code(): void
    {
        config()->set('services.guardian_auth.demo_phone', self::DEMO);
        config()->set('services.guardian_auth.demo_code', '123456');

        $this->assertSame('123456', $this->request(self::DEMO));

        // وثابتٌ فعلاً: الطلب الثاني يعطي الرمز نفسه.
        $this->assertSame('123456', $this->request(self::DEMO));
    }

    public function test_the_fixed_code_actually_signs_in(): void
    {
        config()->set('services.guardian_auth.demo_phone', self::DEMO);
        config()->set('services.guardian_auth.demo_code', '123456');

        $this->request(self::DEMO);

        $this->postJson('/api/guardian/verify-code', [
            'phone' => self::DEMO,
            'code' => '123456',
        ])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_another_number_never_gets_it(): void
    {
        config()->set('services.guardian_auth.demo_phone', self::DEMO);
        config()->set('services.guardian_auth.demo_code', '123456');

        // مطابقةٌ فضفاضة كانت ستمنح رقماً مجاوراً رمزاً معروفاً.
        $this->assertNotSame('123456', $this->request(self::OTHER));
    }

    public function test_an_unset_configuration_opens_nothing(): void
    {
        config()->set('services.guardian_auth.demo_phone', null);
        config()->set('services.guardian_auth.demo_code', null);

        $this->assertNotSame('123456', $this->request(self::DEMO));
    }

    public function test_half_a_configuration_opens_nothing(): void
    {
        // الرقم بلا رمز: إعدادٌ ناقص يجب ألّا يُفسَّر إلى رمزٍ افتراضي.
        config()->set('services.guardian_auth.demo_phone', self::DEMO);
        config()->set('services.guardian_auth.demo_code', null);

        $this->assertNotSame('123456', $this->request(self::DEMO));
    }
}
