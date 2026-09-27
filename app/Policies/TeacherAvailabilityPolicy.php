<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use App\Policies\Concerns\ManagesSchoolResource;

/**
 * التفرّغ ملكُ صاحبه.
 *
 * **الأستاذ يضبط تفرّغه هو ولا يضبط تفرّغ غيره** — وهذا هو القيد الجوهري
 * هنا: بلا فصلٍ صريح بين «تفرّغي» و«تفرّغ زميلي» يستطيع أي أستاذ أن يُفرّغ
 * أوقات زميلٍ فيُسقط حصصه من الجدول المولَّد.
 *
 * والإدارة **تقرأ** تفرّغ الجميع لأنها تُحلّل الجدول وتولّده، و**تعدّله** لأنّ
 * أستاذاً قد يُخبرها هاتفياً بينما هو لا يستعمل التطبيق (وهو حال معظم الكادر
 * اليوم: ثلاثة حسابات فقط).
 */
class TeacherAvailabilityPolicy
{
    use ManagesSchoolResource;

    /** قراءة تفرّغ أستاذ بعينه. */
    public function viewFor(User $user, User $teacher): bool
    {
        if (! $this->belongsToSchoolOf($user, $teacher->school_id)) {
            return false;
        }

        return $user->id === $teacher->id || $user->role->isAdministrative();
    }

    /** تعديله: صاحبه، أو الإدارة. */
    public function manageFor(User $user, User $teacher): bool
    {
        if (! $this->belongsToSchoolOf($user, $teacher->school_id)) {
            return false;
        }

        if ($user->id === $teacher->id) {
            return $user->role === UserRole::Teacher || $user->role->isAdministrative();
        }

        return $user->role->isAdministrative();
    }
}
