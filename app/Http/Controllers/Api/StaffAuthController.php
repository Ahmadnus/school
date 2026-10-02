<?php

namespace App\Http\Controllers\Api;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Jobs\SendLoginCode;
use App\Models\StaffOtp;
use App\Models\User;
use App\Rules\PhoneNumber;
use App\Services\WhatsAppSender;
use App\Support\PhoneLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * دخول الكادر برمزٍ على واتساب — إلى جانب كلمة المرور لا بدلاً منها.
 *
 * يُرسَل الرمز فقط لحسابٍ موجود وفعّال وليس وليّ أمر: وليّ الأمر له
 * {@see GuardianAuthController}، ولو دخل من هنا لفتح تطبيق الكادر بحسابه.
 * والردّ متطابق للرقم المعروف والغريب، كما في دخول الأهالي، كي لا يكشف
 * المسارُ من يعمل في المدرسة.
 *
 * الرقم يُقبل بأيّ صيغة ({@see PhoneLookup})، والرمز يُحفظ على الرقم كما في
 * الحساب، فيلتقي الطلب والتحقّق ولو اختلفت الكتابة بينهما.
 */
class StaffAuthController extends Controller
{
    /** Step one: issue a code. */
    public function requestCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', new PhoneNumber],
        ]);

        $user = self::staffFor($data['phone']);

        if ($user !== null && WhatsAppSender::isConfigured()) {
            // A fresh request invalidates earlier codes for the same phone.
            StaffOtp::query()
                ->where('phone', $user->phone)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            StaffOtp::create([
                'phone' => $user->phone,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(StaffOtp::TTL_MINUTES),
            ]);

            SendLoginCode::dispatch($user->phone, $user->school?->phone_country_code ?? '963', $code);
        }

        return response()->json([
            'message' => __('messages.guardian_auth.code_sent'),
        ]);
    }

    /** Step two: exchange the code for a Sanctum token. */
    public function verifyCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', new PhoneNumber],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $user = self::staffFor($data['phone']);

        $otp = $user === null ? null : StaffOtp::query()
            ->where('phone', $user->phone)
            ->usable()
            ->latest('id')
            ->first();

        if ($otp === null || $user === null || ! Hash::check($data['code'], $otp->code_hash)) {
            // Count the attempt so guessing burns the code rather than the clock.
            $otp?->increment('attempts');

            throw ValidationException::withMessages([
                'code' => __('messages.guardian_auth.code_invalid'),
            ]);
        }

        $otp->update(['consumed_at' => now()]);

        // Same shape as password login so the app reads one contract.
        return response()->json([
            'message' => __('messages.auth.logged_in'),
            'token' => $user->createToken($request->input('device_name', 'api'))->plainTextToken,
            'data' => new UserResource($user->load('school')),
        ]);
    }

    /** An active staff account on this phone, or `null`. */
    private static function staffFor(string $phone): ?User
    {
        $user = User::query()->whereIn('phone', PhoneLookup::candidates($phone))->first();

        if ($user === null || $user->role->isGuardian() || $user->status !== Status::Active) {
            return null;
        }

        return $user;
    }
}
