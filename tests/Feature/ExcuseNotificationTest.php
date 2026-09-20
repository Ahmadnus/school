<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AbsenceExcuse;
use App\Models\AcademicYear;
use App\Models\Grade;
use App\Models\Guardian;
use App\Models\Notification;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentGuardian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * عذر الغياب محادثة من دورين، وكلاهما كان صامتاً:
 * الوليّ يقدّم فلا يعلم أحد في المدرسة، والمدرسة تقرّر فلا يعلم الوليّ.
 */
class ExcuseNotificationTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Student $child;

    private User $admin;

    private User $guardianUser;

    private User $otherGuardianUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $year = AcademicYear::factory()->current()->create(['school_id' => $this->school->id]);
        $grade = Grade::factory()->create(['school_id' => $this->school->id]);
        $section = Section::factory()->create([
            'grade_id' => $grade->id,
            'academic_year_id' => $year->id,
        ]);

        $this->child = Student::factory()->create(['school_id' => $this->school->id]);
        $classmate = Student::factory()->create(['school_id' => $this->school->id]);

        foreach ([$this->child, $classmate] as $student) {
            StudentEnrollment::factory()->create([
                'student_id' => $student->id,
                'section_id' => $section->id,
                'academic_year_id' => $year->id,
            ]);
        }

        $this->admin = User::factory()->role(UserRole::Admin)->create(['school_id' => $this->school->id]);
        $this->guardianUser = $this->linkGuardian($this->child);
        $this->otherGuardianUser = $this->linkGuardian($classmate);
    }

    private function linkGuardian(Student $student): User
    {
        $user = User::factory()->role(UserRole::Guardian)->create(['school_id' => $this->school->id]);
        $guardian = Guardian::factory()->create(['school_id' => $this->school->id, 'user_id' => $user->id]);
        StudentGuardian::factory()->create(['student_id' => $student->id, 'guardian_id' => $guardian->id]);

        return $user;
    }

    private function submit(): void
    {
        Sanctum::actingAs($this->guardianUser);

        $this->postJson('/api/excuses', [
            'student_id' => $this->child->id,
            'start_date' => '2026-09-14',
            'end_date' => '2026-09-15',
            'reason' => 'مرض',
        ])->assertCreated();
    }

    public function test_submitting_an_excuse_notifies_the_office(): void
    {
        $this->submit();

        $notification = Notification::where('user_id', $this->admin->id)
            ->where('type', 'excuse_submitted')
            ->first();

        $this->assertNotNull($notification, 'الإدارة لم تُبلَّغ بالعذر الجديد');
        $this->assertStringContainsString($this->child->full_name, $notification->title);
        // السبب في المتن: قرار القبول يُتّخذ من القائمة بلا فتح كل عذر.
        $this->assertStringContainsString('مرض', $notification->body);
    }

    public function test_submitting_does_not_notify_the_guardian_who_filed_it(): void
    {
        $this->submit();

        // هو من قدّمه للتوّ؛ إشعار بفعله هو ضجيج.
        $this->assertSame(0, Notification::where('user_id', $this->guardianUser->id)->count());
    }

    public function test_accepting_notifies_only_this_child_guardians(): void
    {
        $this->submit();
        $excuse = AbsenceExcuse::firstOrFail();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/excuses/{$excuse->id}/review", [
            'status' => 'accepted',
        ])->assertOk();

        $notification = Notification::where('user_id', $this->guardianUser->id)
            ->where('type', 'excuse_reviewed')
            ->first();

        $this->assertNotNull($notification, 'الوليّ لم يعرف نتيجة عذره');
        // النصّ يُقارَن بمفتاحه لا بحروفه: لغة الاختبارات ليست لغة المدرسة.
        $this->assertSame(
            __('notifications.excuse_accepted_body', ['from' => '2026-09-14', 'to' => '2026-09-15']),
            $notification->body,
        );

        // وليّ أمر طالب آخر لا شأن له بعذر هذا الطالب.
        $this->assertSame(
            0,
            Notification::where('user_id', $this->otherGuardianUser->id)
                ->where('type', 'excuse_reviewed')
                ->count(),
        );
    }

    public function test_rejection_says_so_plainly(): void
    {
        $this->submit();
        $excuse = AbsenceExcuse::firstOrFail();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/excuses/{$excuse->id}/review", [
            'status' => 'rejected',
            'review_note' => 'بلا وثيقة طبية',
        ])->assertOk();

        $body = Notification::where('user_id', $this->guardianUser->id)
            ->where('type', 'excuse_reviewed')
            ->value('body');

        // «روجعت» وحدها تُقرأ قبولاً، فيظنّ الأهل أن الغياب سُوّي: لا بدّ
        // أن يحمل المتن صيغة **الرفض** لا صيغة المراجعة.
        $rejected = __('notifications.excuse_rejected_body', ['from' => '2026-09-14', 'to' => '2026-09-15']);
        $accepted = __('notifications.excuse_accepted_body', ['from' => '2026-09-14', 'to' => '2026-09-15']);
        $this->assertStringContainsString($rejected, $body);
        $this->assertStringNotContainsString($accepted, $body);
        $this->assertStringContainsString('بلا وثيقة طبية', $body);
    }
}
