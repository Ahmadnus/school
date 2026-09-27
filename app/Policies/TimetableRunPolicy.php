<?php

namespace App\Policies;

use App\Models\TimetableRun;
use App\Models\User;
use App\Policies\Concerns\ManagesSchoolResource;

/**
 * من يضبط دوام المعهد ويولّد الجدول: الإدارة وحدها.
 *
 * **الأستاذ لا يولّد الجدول ولا يضبط دوام المعهد.** توليدٌ واحد يُعيد ترتيب
 * أوقات الكادر كلّه وكل الشعب، فهو قرار إدارة لا قرار من يتأثّر به. ويبقى
 * للأستاذ ما هو له: تفرّغه (`TeacherAvailabilityPolicy`)، وإسناداته، وجدوله
 * (`UserPolicy::viewTimetable`).
 *
 * وصلاحيات الإدارة القائمة لا تُضيَّق: `isAdministrative()` هي نفس البوّابة
 * المستعملة في بقيّة النظام.
 */
class TimetableRunPolicy
{
    use ManagesSchoolResource;

    /** ضبط دوام المعهد، وإدارة الإسنادات، والتحليل، والتوليد، والتعديل اليدويّ. */
    public function manage(User $user): bool
    {
        return $user->role->isAdministrative();
    }

    /** قراءة الجدول كاملاً — كل الأساتذة وكل الشعب في نظرة واحدة. */
    public function viewAll(User $user): bool
    {
        return $user->role->isAdministrative();
    }

    public function view(User $user, TimetableRun $run): bool
    {
        return $this->belongsToSchoolOf($user, $run->school_id)
            && $user->role->isAdministrative();
    }
}
