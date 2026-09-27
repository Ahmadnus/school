<?php

namespace App\Services;

use App\Enums\NotificationApp;
use App\Models\Assessment;
use App\Models\Student;

/**
 * إشعار وليّ الأمر بدرجة التسميع.
 *
 * يمرّ بالبنية القائمة كلّها ولا يبني ثانيةً: `NotificationGate` يفحص مفتاح
 * المدرسة ثمّ مفتاح المستخدم، والسجلّ يُحفظ في جدول الإشعارات، والتسليم
 * بـ`DeliverNotification` بعد إرسال الردّ (بلا عامل طوابير — الاستضافة مشتركة).
 *
 * **كل أبٍ يسمع درجة ابنه وحده.** لا كشف شعبة ولا مقارنة: الإشعار يُبنى لكل
 * طالب على حدة من صفّه هو، ويُرسَل إلى أوليائه هم. تسريب درجة طالب إلى أبٍ
 * آخر خطأٌ لا يُصلَح باعتذار.
 *
 * **ولا يُقال «أُرسل» لمن لم يصله شيء.** حساب وليّ الأمر يُنشأ عند أوّل تسجيل
 * دخول بالرمز، فمن أُدخل اسمه ورقمه ولم يفتح التطبيق بعد لا يصله إشعار. حالته
 * غير حالة من لا وليّ أمر له: الأوّل يُتابَع بمكالمة، والثاني يُدخَل وليّه.
 * فيُفصَلان في التقرير كما في تذكير الرسوم.
 */
class TasmiNotifier
{
    /**
     * @param  list<int>  $studentIds  من تغيّرت درجته فقط — حفظٌ بلا تعديل لا يُشعر
     * @return array{sent:int, not_signed_in:list<string>, without_guardian:list<string>}
     */
    public static function recorded(Assessment $assessment, array $studentIds): array
    {
        $assessment->loadMissing(['subject', 'section.grade', 'type']);

        $sent = 0;
        $notSignedIn = [];
        $withoutGuardian = [];

        if ($studentIds === []) {
            return self::result($sent, $notSignedIn, $withoutGuardian);
        }

        $scores = $assessment->scores()
            ->whereIn('student_id', $studentIds)
            ->whereNotNull('score')
            ->with(['student.guardians.user'])
            ->get();

        $subject = $assessment->subject?->name ?? '';
        $max = self::plain($assessment->max_score);
        $date = $assessment->held_on?->translatedFormat('j F Y')
            ?? $assessment->created_at?->translatedFormat('j F Y')
            ?? '—';

        foreach ($scores as $score) {
            $student = $score->student;

            if (! $student instanceof Student) {
                continue;
            }

            $recipients = $student->guardians->filter(fn ($guardian) => $guardian->user !== null);

            if ($recipients->isEmpty()) {
                if ($student->guardians->isNotEmpty()) {
                    $notSignedIn[] = $student->full_name;
                } else {
                    $withoutGuardian[] = $student->full_name;
                }

                continue;
            }

            foreach ($recipients as $guardian) {
                $notification = NotificationGate::notify(
                    $guardian->user,
                    'tasmi_recorded',
                    __('notifications.tasmi_recorded_title', [
                        'name' => $student->first_name,
                        'subject' => $subject,
                    ]),
                    __('notifications.tasmi_recorded_body', [
                        'score' => self::plain($score->score),
                        'max' => $max,
                        'subject' => $subject,
                        'date' => $date,
                    ]),
                    $student->id,
                    NotificationApp::Guardian,
                );

                // البوّابة تُعيد `null` إن أغلقت المدرسة المفتاح أو أغلقه
                // المستخدم. لا يُحسَب ذلك إرسالاً.
                if ($notification !== null) {
                    $sent++;
                }
            }
        }

        return self::result($sent, $notSignedIn, $withoutGuardian);
    }

    /** @param  list<string>  $notSignedIn */
    private static function result(int $sent, array $notSignedIn, array $withoutGuardian): array
    {
        return [
            'sent' => $sent,
            'not_signed_in' => array_values(array_unique($notSignedIn)),
            'without_guardian' => array_values(array_unique($withoutGuardian)),
        ];
    }

    private static function plain(mixed $value): string
    {
        $text = (string) $value;

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }
}
