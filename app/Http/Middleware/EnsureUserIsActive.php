<?php

namespace App\Http\Middleware;

use App\Enums\Status;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * حسابٌ أوقفته الإدارة يخرج فوراً، لا عند انتهاء توكنه.
 *
 * الدخول يرفض الحساب الموقوف، لكنّ التوكن الصادر قبل الإيقاف كان يبقى صالحاً
 * بلا نهاية: أستاذٌ غادر المدرسة يظلّ يقرأ أسماء الطلاب ويراسل أهاليهم. فيُفحَص
 * مع كل طلب، ويُسحب التوكن، ويعود ٤٠١ فيمسح التطبيقُ الجلسة من تلقائه.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->status !== Status::Active) {
            $user->currentAccessToken()?->delete();

            return response()->json(['message' => __('messages.auth.inactive_account')], 401);
        }

        return $next($request);
    }
}
