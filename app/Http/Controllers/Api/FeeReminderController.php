<?php

namespace App\Http\Controllers\Api;

use App\Enums\NotificationApp;
use App\Http\Controllers\Controller;
use App\Models\FeePlan;
use App\Models\Student;
use App\Services\FeeReminderCandidates;
use App\Services\NotificationGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * تذكير المتأخّرين عن السداد.
 *
 * الشاشة تعرض القائمة أوّلاً ويُزيل منها المستخدم من يشاء، ثم يُرسل —
 * لأن تذكيراً يصل أباً سدّد أمس يُفقد المدرسة مصداقيّتها، ويُدرّب الباقين
 * على تجاهل رسائلها. فالقرار الأخير لبشر لا لاستعلام.
 */
class FeeReminderController extends Controller
{
    /** من عليه متبقٍّ ولم يدفع منذ المدّة المحدّدة. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', FeePlan::class);

        $days = max(1, min(365, $request->integer('days', 30)));

        return response()->json([
            'data' => FeeReminderCandidates::for($request->user()->school_id, $days),
            'meta' => ['days' => $days],
        ]);
    }

    /** يُرسل التذكير إلى أولياء أمور الطلاب المحدّدين. */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('viewAny', FeePlan::class);

        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:1', 'max:300'],
            'student_ids.*' => ['integer'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $students = Student::query()
            ->where('school_id', $request->user()->school_id)
            ->whereIn('id', $data['student_ids'])
            ->with(['guardians.user', 'feePlans.activePayments'])
            ->get();

        $sent = 0;
        $withoutGuardian = [];

        foreach ($students as $student) {
            $remaining = $student->feePlans
                ->reject(fn (FeePlan $plan) => $plan->status->value === 'cancelled')
                ->reduce(
                    fn ($carry, FeePlan $plan) => $carry->plus($plan->remainingAmount()),
                    \App\Support\Money::zero(),
                );

            // يُعاد الحساب عند الإرسال لا يُؤخذ من الشاشة: قد يكون الأب
            // سدّد بين فتح القائمة والضغط على «إرسال».
            if (! $remaining->isPositive()) {
                continue;
            }

            $recipients = $student->guardians->filter(fn ($g) => $g->user !== null);

            if ($recipients->isEmpty()) {
                $withoutGuardian[] = $student->full_name;

                continue;
            }

            foreach ($recipients as $guardian) {
                NotificationGate::notify(
                    $guardian->user,
                    'fee_due',
                    __('notifications.fee_due_title', ['name' => $student->full_name]),
                    trim(
                        __('notifications.fee_reminder_body', [
                            'amount' => (string) $remaining->toDecimal(),
                        ]).' '.($data['note'] ?? ''),
                    ),
                    $student->id,
                    NotificationApp::Guardian,
                );
                $sent++;
            }
        }

        return response()->json([
            'message' => __('messages.fee_reminder.sent', ['count' => $sent]),
            'data' => [
                'sent' => $sent,
                // من لا وليّ أمر له لم يصله شيء؛ إخفاء ذلك يجعل المدرسة
                // تظنّ أنها طالبت وهي لم تفعل.
                'without_guardian' => $withoutGuardian,
            ],
        ]);
    }
}
