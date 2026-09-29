<?php

namespace App\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * يسجّل الطلبات البطيئة وحدها — مراقبةٌ دائمة بدل اختبارٍ لحظي.
 *
 * اختبار الحمل يقول كيف يتصرّف النظام في ساعةٍ اخترتُها أنا، بحمل اخترعتُه
 * أنا، من عنوانٍ واحد. وهذا يقول ما يشعر به **مستخدموك** فعلاً، كل يوم، من
 * شبكاتهم — وهو ما لا يُستدلّ عليه ولا يُحاكى.
 *
 * وسجلّ Apache لا يكفي بديلاً: صيغته المعتادة في cPanel بلا `%D`، فلا تحمل
 * زمن الطلب. وتعديلها يحتاج root، وهذا لا يحتاج شيئاً.
 *
 * البطيء وحده يُكتب، فالسجلّ يبقى قابلاً للقراءة: ملفٌّ فيه كل طلب لا يُقرأ،
 * وما لا يُقرأ لا يُصلَح شيئاً. وعدّاد الاستعلامات معه لأنّ السؤال التالي
 * دائماً «أبطأه المنطق أم كثرةُ أسئلة القاعدة؟».
 */
class SlowRequestLog
{
    /** عدّاد استعلامات الطلب الجاري. */
    private static int $queries = 0;

    /**
     * يربط المراقبة بدورة حياة الطلب.
     *
     * تُستدعى مرّةً من `AppServiceProvider`. والأوامر السطرية لا تُسجَّل بلا
     * حارسٍ لها: `RequestHandled` حدثُ نواةِ HTTP، ولا يقع في أمرٍ سطريّ
     * أصلاً — وحارسٌ زائد كان يجعل المراقبة غير قابلة للاختبار.
     */
    public static function watch(): void
    {
        if (self::thresholdMs() <= 0) {
            return;
        }

        self::$queries = 0;

        // العدّ إضافةُ رقمٍ لا أكثر — أرخص من أن يُلاحَظ، ويجيب السؤال الذي
        // يليه دائماً: أبطأه المنطق أم كثرة الاستعلامات؟
        Event::listen(QueryExecuted::class, static function (): void {
            self::$queries++;
        });

        Event::listen(RequestHandled::class, static function (RequestHandled $event): void {
            self::record($event);
        });
    }

    private static function record(RequestHandled $event): void
    {
        $threshold = self::thresholdMs();

        // الإطفاء يُفحص هنا أيضاً لا عند الربط وحده: المزوّد يربط المراقبة عند
        // الإقلاع، فلو غُيّرت العتبة بعده لبقي الربط قائماً — وصفرٌ يعني
        // «لا تكتب»، لا «اكتب كل شيء لأنّ لا شيء أقلّ من صفر».
        if ($threshold <= 0) {
            return;
        }

        $ms = self::elapsedMs();

        if ($ms === null || $ms < $threshold) {
            return;
        }

        $request = $event->request;

        Log::channel('slow')->warning('طلب بطيء', [
            'ms' => round($ms),
            'queries' => self::$queries,
            'method' => $request->method(),
            // المسار المنقّح: `/api/sections/7/schedule/week` تصير
            // `/api/sections/{id}/schedule/week`، فتتجمّع الطلبات المتشابهة
            // بدل أن يصير لكل شعبةٍ سطرٌ مستقلّ لا يُقارَن بغيره.
            'path' => self::normalise($request->path()),
            'status' => $event->response->getStatusCode(),
            // الدور لا الاسم ولا المعرّف: يكفي لمعرفة أي تطبيقٍ بطؤ، ولا
            // يضع بيانات أشخاصٍ في ملفٍّ نصّي.
            'role' => $request->user()?->role?->value,
        ]);
    }

    /** من بدء الطلب لا من بدء المتحكّم: الإقلاع جزءٌ ممّا ينتظره المستخدم. */
    private static function elapsedMs(): ?float
    {
        if (! defined('LARAVEL_START')) {
            return null;
        }

        return (microtime(true) - LARAVEL_START) * 1000;
    }

    private static function thresholdMs(): int
    {
        return (int) config('logging.slow_request_ms', 1000);
    }

    /** يستبدل الأرقام في المسار بـ`{id}`. */
    private static function normalise(string $path): string
    {
        return preg_replace('#/\d+#', '/{id}', '/'.ltrim($path, '/')) ?? $path;
    }
}
