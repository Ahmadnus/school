<?php

namespace Tests\Feature;

use App\Support\SlowRequestLog;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * مراقبة الطلبات البطيئة: تكتب ما يستحقّ، وتصمت عمّا لا يستحقّ.
 *
 * سجلٌّ فيه كل طلب لا يُقرأ، وما لا يُقرأ لا يُصلح شيئاً. فالقيمة في أنّ
 * البطيء وحده يظهر.
 */
class SlowRequestLogTest extends TestCase
{
    /** يشغّل دورة طلبٍ استغرق [$ms] ويردّ ما سُجِّل. */
    private function handled(float $ms, int $status = 200): void
    {
        // `LARAVEL_START` هي مرجع القياس: تُزاح إلى الماضي لتمثيل طلبٍ طال.
        if (! defined('LARAVEL_START')) {
            define('LARAVEL_START', microtime(true) - ($ms / 1000));
        }

        SlowRequestLog::watch();

        event(new RequestHandled(
            Request::create('/api/sections/7/schedule/week', 'GET'),
            new Response('', $status),
        ));
    }

    public function test_a_slow_request_is_written_with_its_cost(): void
    {
        config(['logging.slow_request_ms' => 1]);

        $written = [];
        Log::shouldReceive('channel')->with('slow')->andReturnSelf();
        Log::shouldReceive('warning')->andReturnUsing(function ($message, $context) use (&$written) {
            $written[] = $context;
        });

        $this->handled(ms: 2500);

        $this->assertNotEmpty($written, 'الطلب البطيء لم يُسجَّل.');
        $this->assertSame('GET', $written[0]['method']);
        $this->assertSame(200, $written[0]['status']);
        // المسار منقّح: كل شعبةٍ لا تصنع سطراً مستقلّاً لا يُقارَن بغيره.
        $this->assertSame('/api/sections/{id}/schedule/week', $written[0]['path']);
        $this->assertArrayHasKey('queries', $written[0]);
    }

    public function test_a_fast_request_writes_nothing(): void
    {
        // عتبةٌ عالية جدّاً: لا طلب في هذا الاختبار يبلغها.
        config(['logging.slow_request_ms' => 600000]);

        Log::shouldReceive('channel')->never();

        $this->handled(ms: 5);

        $this->addToAssertionCount(1);
    }

    public function test_setting_the_threshold_to_zero_turns_the_watch_off(): void
    {
        config(['logging.slow_request_ms' => 0]);

        Log::shouldReceive('channel')->never();

        $this->handled(ms: 9000);

        $this->addToAssertionCount(1);
    }
}
