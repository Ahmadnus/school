<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Guardian;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * حساب وليّ الأمر — يُوجَد أو يُنشأ من سجلّه.
 *
 * كان الحساب لا يُنشأ إلاّ عند أوّل دخولٍ برمز، فـ`guardians.user_id` يبقى
 * فارغاً قبله. وهذا كان يُسقط المراسلة صامتةً: يفتح الموظّف خيطاً عن طالب،
 * فلا يجد الخادم لوليّ أمره حساباً يجعله طرفاً، فيُحفظ الخيط بمشاركٍ واحد هو
 * المرسِل — لا يصل إلى أحد ولا يُنشئ إشعاراً، ولا أثر يدلّ على الضياع.
 *
 * فصار الحساب يُنشأ عند الحاجة إليه **مستلِماً** أيضاً، لا عند الدخول وحده.
 * وهو الحساب نفسه الذي كان الدخول سيصنعه: بلا كلمة سر مستعملة، يدخل بالرمز.
 * ومن هنا صار هذا الموضع مصدراً واحداً للإنشاء، بدل نسختين تفترقان.
 */
class GuardianAccount
{
    /**
     * حساب هذا الوليّ، يُنشأ إن لم يكن له حساب.
     *
     * `null` لوليّ أمرٍ بلا هاتف: الهاتف هو هويّته في الدخول، وحسابٌ بلا
     * هويّة لا يستطيع صاحبه أن يدخل إليه — فلا يُصنَع وهماً.
     */
    public static function for(Guardian $guardian): ?User
    {
        if ($guardian->user_id !== null) {
            return $guardian->user;
        }

        if (blank($guardian->phone)) {
            return null;
        }

        // الهاتف فريدٌ داخل المدرسة (`users.unique(school_id, phone)`): فحسابٌ
        // قائم بالرقم نفسه يُربَط ولا يُستنسَخ — وإلاّ انكسر القيد، أو صار
        // لوليّ الأمر حسابان يقرأ في أحدهما ويصله الإشعار في الآخر.
        $user = User::query()
            ->where('school_id', $guardian->school_id)
            ->where('phone', $guardian->phone)
            ->first();

        if ($user === null) {
            $names = preg_split('/\s+/', trim((string) $guardian->name), 2);

            $user = User::create([
                'school_id' => $guardian->school_id,
                'first_name' => $names[0] ?: $guardian->phone,
                'last_name' => $names[1] ?? null,
                'phone' => $guardian->phone,
                'role' => UserRole::Guardian,
                // بلا كلمة سر: هذا الحساب يدخل بالرمز وحده.
                'password' => Hash::make(Str::random(40)),
            ]);
        }

        $guardian->update(['user_id' => $user->id]);

        return $user;
    }

    /**
     * حسابات أولياء أمر الطالب — من لهم هويّة يُراسَلون بها.
     *
     * @param  Collection<int, Guardian>  $guardians
     * @return Collection<int, int>
     */
    public static function idsFor($guardians)
    {
        return $guardians
            ->map(fn (Guardian $guardian) => self::for($guardian)?->id)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }
}
