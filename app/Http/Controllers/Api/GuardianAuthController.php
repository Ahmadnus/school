<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Guardian;
use App\Models\GuardianOtp;
use App\Models\User;
use App\Rules\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

        $guardian = Guardian::query()->where('phone', $data['phone'])->first();

        if ($guardian !== null) {
            // A fresh request invalidates earlier codes for the same phone.
            GuardianOtp::query()
                ->where('phone', $data['phone'])
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            $code = self::demoCodeFor($data['phone'])
                ?? str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            GuardianOtp::create([
                'phone' => $data['phone'],
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(GuardianOtp::TTL_MINUTES),
            ]);

            // No SMS provider yet. Pre-launch the code is handed back in the
            // response so the app can fill it in; the log line stays for
            // debugging. Both go away once a provider is wired.
            Log::info('Guardian OTP issued', ['phone' => $data['phone'], 'code' => $code]);

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

        $otp = GuardianOtp::query()
            ->where('phone', $data['phone'])
            ->usable()
            ->latest('id')
            ->first();

        if ($otp === null || ! Hash::check($data['code'], $otp->code_hash)) {
            // Count the attempt so guessing burns the code rather than the clock.
            $otp?->increment('attempts');

            throw ValidationException::withMessages([
                'code' => __('messages.guardian_auth.code_invalid'),
            ]);
        }

        $guardian = Guardian::query()->where('phone', $data['phone'])->firstOrFail();

        $user = DB::transaction(function () use ($guardian, $otp) {
            $otp->update(['consumed_at' => now()]);

            if ($guardian->user_id !== null) {
                return $guardian->user;
            }

            // First sign-in: the account is created from the guardian record.
            $names = preg_split('/\s+/', trim($guardian->name), 2);

            $user = User::create([
                'school_id' => $guardian->school_id,
                'first_name' => $names[0] ?? $guardian->name,
                'last_name' => $names[1] ?? null,
                'phone' => $guardian->phone,
                'role' => UserRole::Guardian,
                // No password: this account signs in by code only.
                'password' => Hash::make(Str::random(40)),
            ]);

            $guardian->update(['user_id' => $user->id]);

            return $user;
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
