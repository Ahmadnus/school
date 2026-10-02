<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Jobs\SendLoginCode;
use App\Models\Guardian;
use App\Models\GuardianOtp;
use App\Models\User;
use App\Rules\PhoneNumber;
use App\Services\GuardianAccount;
use App\Services\WhatsAppSender;
use App\Support\PhoneLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Sign-in for the guardian app: phone number + one-time code.
 *
 * Guardians are created by the office, not by self-registration, so a code is
 * only ever sent to a phone that already exists in `guardians`. The response
 * is deliberately identical for known and unknown numbers — otherwise the
 * endpoint would confirm which families attend the school.
 *
 * The account in `users` is created lazily on first successful sign-in, so
 * offices that never adopt the app carry no dormant accounts.
 */
class GuardianAuthController extends Controller
{
    /** Step one: issue a code. */
    public function requestCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', new PhoneNumber],
        ]);

        // بأيّ صيغة كُتب الرقم: `+963…` أو `0…` أو بلا صفر ({@see PhoneLookup}).
        $guardian = self::guardianFor($data['phone']);

        if ($guardian !== null) {
            // الرمز يُحفظ على الرقم كما سجّلته المدرسة، فيلتقي الطلب والتحقّق
            // ولو اختلفت صيغة الكتابة بينهما.
            $phone = $guardian->phone;

            // A fresh request invalidates earlier codes for the same phone.
            GuardianOtp::query()
                ->where('phone', $phone)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            $code = self::demoCodeFor($phone)
                ?? str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            GuardianOtp::create([
                'phone' => $phone,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(GuardianOtp::TTL_MINUTES),
            ]);

            // With the WhatsApp gateway wired the code travels there and never
            // touches the log. Without it, the log line is the only way to
            // read the code while debugging.
            if (WhatsAppSender::isConfigured()) {
                SendLoginCode::for(
                    $guardian->phone,
                    $guardian->school?->phone_country_code ?? '963',
                    $code,
                    (string) $guardian->school?->name,
                );
            } else {
                Log::info('Guardian OTP issued', ['phone' => $phone, 'code' => $code]);
            }

            if (config('services.guardian_auth.expose_code')) {
                return response()->json([
                    'message' => __('messages.guardian_auth.code_sent'),
                    'data' => ['code' => $code],
                ]);
            }
        }

        return response()->json([
            'message' => __('messages.guardian_auth.code_sent'),
        ]);
    }

    private static function guardianFor(string $phone): ?Guardian
    {
        return Guardian::query()->whereIn('phone', PhoneLookup::candidates($phone))->first();
    }

    /**
     * الرمز الثابت للرقم التجريبي، أو `null` فيبقى العشوائي.
     *
     * الرقم يُقارَن بالتساوي التامّ لا بالاحتواء: مطابقةٌ فضفاضة هنا تعني
     * رمزاً معروفاً لأرقام لم تُقصَد. والمقارنة تُجرى فقط حين يكون
     * المتغيّران مضبوطين معاً، فالإعداد الناقص لا يفتح شيئاً.
     */
    private static function demoCodeFor(string $phone): ?string
    {
        $demoPhone = config('services.guardian_auth.demo_phone');
        $demoCode = config('services.guardian_auth.demo_code');

        if (blank($demoPhone) || blank($demoCode)) {
            return null;
        }

        if ($phone !== $demoPhone) {
            return null;
        }

        // يُسجَّل كي لا يمرّ استعماله صامتاً في سجلّ الإنتاج.
        Log::info('Guardian OTP: demo code issued', ['phone' => $phone]);

        return str_pad((string) $demoCode, 6, '0', STR_PAD_LEFT);
    }

    /** Step two: exchange the code for a Sanctum token. */
    public function verifyCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', new PhoneNumber],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $guardian = self::guardianFor($data['phone']);

        $otp = $guardian === null ? null : GuardianOtp::query()
            ->where('phone', $guardian->phone)
            ->usable()
            ->latest('id')
            ->first();

        if ($guardian === null || $otp === null || ! Hash::check($data['code'], $otp->code_hash)) {
            // Count the attempt so guessing burns the code rather than the clock.
            $otp?->increment('attempts');

            throw ValidationException::withMessages([
                'code' => __('messages.guardian_auth.code_invalid'),
            ]);
        }

        // الإنشاء في {@see GuardianAccount}: الحساب يلزم عند الدخول وعند
        // المراسلة سواءً، ونسختان منه تفترقان عند أوّل تعديل.
        $user = DB::transaction(function () use ($guardian, $otp) {
            $otp->update(['consumed_at' => now()]);

            return GuardianAccount::for($guardian);
        });

        // Same shape as staff login (`token` at the top level, `data` the
        // user) so the app reads one contract for both sign-in paths.
        return response()->json([
            'message' => __('messages.auth.logged_in'),
            'token' => $user->createToken('guardian-app')->plainTextToken,
            'data' => new UserResource($user->load('school')),
        ]);
    }
}
