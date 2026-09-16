<?php

use App\Http\Controllers\Api\AbsenceExcuseController;
use App\Http\Controllers\Api\AcademicYearController;
use App\Http\Controllers\Api\AssessmentController;
use App\Http\Controllers\Api\AssessmentTypeController;
use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BehaviorRecordController;
use App\Http\Controllers\Api\BulkEnrollmentController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\FeePaymentController;
use App\Http\Controllers\Api\FeePlanController;
use App\Http\Controllers\Api\FeeTypeController;
use App\Http\Controllers\Api\GateAttendanceController;
use App\Http\Controllers\Api\GradeController;
use App\Http\Controllers\Api\GradeScoreController;
use App\Http\Controllers\Api\GuardianAuthController;
use App\Http\Controllers\Api\GuardianController;
use App\Http\Controllers\Api\GuardianDigestController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\HonorEntryController;
use App\Http\Controllers\Api\InsightsController;
use App\Http\Controllers\Api\MessageableController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\PostTypeController;
use App\Http\Controllers\Api\ReportCardController;
use App\Http\Controllers\Api\RubricController;
use App\Http\Controllers\Api\ScheduleSlotController;
use App\Http\Controllers\Api\SchoolController;
use App\Http\Controllers\Api\SectionController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\StudentEnrollmentController;
use App\Http\Controllers\Api\StudentGuardianController;
use App\Http\Controllers\Api\StudentImportController;
use App\Http\Controllers\Api\StudentNoteController;
use App\Http\Controllers\Api\StudentProfileController;
use App\Http\Controllers\Api\SubjectController;
use App\Http\Controllers\Api\SupervisorScopeController;
use App\Http\Controllers\Api\TeacherAssignmentController;
use App\Http\Controllers\Api\TermController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserPreferenceController;
use Illuminate\Support\Facades\Route;

Route::post('login', [AuthController::class, 'login'])->middleware('throttle:6,1');
// Guardian app sign-in: phone + one-time code. Public, and rate limited
// because both steps accept an unauthenticated phone number.
Route::post('guardian/request-code', [GuardianAuthController::class, 'requestCode'])
    ->middleware('throttle:5,1');
Route::post('guardian/verify-code', [GuardianAuthController::class, 'verifyCode'])
    ->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);

    // --- School setup (§2.1) ---
    Route::get('school', [SchoolController::class, 'show']);
    Route::match(['put', 'post'], 'school', [SchoolController::class, 'update']);

    // --- Staff / users (§2.2) ---
    Route::get('users/roles', [UserController::class, 'roles']);
    // Supervision scope — the table shipped with the schema but had no routes.
    Route::get('users/{user}/scopes', [SupervisorScopeController::class, 'index']);
    Route::post('users/{user}/scopes', [SupervisorScopeController::class, 'store']);
    Route::delete('users/{user}/scopes/{section}', [SupervisorScopeController::class, 'destroy']);
    Route::apiResource('users', UserController::class);

    // --- Academic years and terms (§2.1) ---
    Route::get('academic-years/current', [AcademicYearController::class, 'current']);
    Route::post('academic-years/{academic_year}/current', [AcademicYearController::class, 'markCurrent']);
    Route::apiResource('academic-years', AcademicYearController::class)
        ->parameters(['academic-years' => 'academic_year']);

    Route::get('academic-years/{academic_year}/terms', [TermController::class, 'index']);
    Route::post('academic-years/{academic_year}/terms', [TermController::class, 'store']);
    Route::post('terms/{term}/current', [TermController::class, 'markCurrent']);
    Route::apiResource('terms', TermController::class)->only(['update', 'destroy']);

    // --- Grades and sections (§2.1) ---
    Route::post('grades/reorder', [GradeController::class, 'reorder']);
    Route::get('grades/{grade}/students', [GradeController::class, 'students']);
    Route::post('grades/{grade}/sections/reorder', [SectionController::class, 'reorder']);
    Route::apiResource('grades', GradeController::class);

    Route::get('sections/{section}/students', [SectionController::class, 'students']);
    Route::apiResource('sections', SectionController::class);

    // --- Students and enrollments (§2.2) ---
    Route::get('students/{student}/enrollments', [StudentEnrollmentController::class, 'index']);
    Route::post('students/{student}/enrollments', [StudentEnrollmentController::class, 'store']);
    Route::apiResource('enrollments', StudentEnrollmentController::class)->only(['update', 'destroy']);
    // Bulk enrollment / year promotion: preview first, then apply.
    Route::post('enrollments/bulk/preview', [BulkEnrollmentController::class, 'preview']);
    Route::post('enrollments/bulk', [BulkEnrollmentController::class, 'store']);
    // Students flagged by the attention signals — before the resource so
    // `attention` is not swallowed by `{student}`.
    Route::get('students/attention', [StudentProfileController::class, 'attention']);
    Route::apiResource('students', StudentController::class);

    // --- Student profile: header bundle + the sections behind its buttons ---
    Route::get('students/{student}/profile', [StudentProfileController::class, 'show']);
    Route::get('students/{student}/subjects', [StudentProfileController::class, 'subjects']);
    Route::get('students/{student}/attendance-summary', [StudentProfileController::class, 'attendanceSummary']);
    Route::get('students/{student}/contacts', [StudentProfileController::class, 'contacts']);
    Route::get('students/{student}/signals', [StudentProfileController::class, 'signals']);
    Route::get('students/{student}/timeline', [StudentProfileController::class, 'timeline']);

    // Guardian app: this week for each child, in one call.
    Route::get('guardian/digest', [GuardianDigestController::class, 'show']);

    Route::get('students/{student}/notes', [StudentNoteController::class, 'index']);
    Route::post('students/{student}/notes', [StudentNoteController::class, 'store']);
    Route::match(['put', 'patch'], 'student-notes/{note}', [StudentNoteController::class, 'update']);
    Route::delete('student-notes/{note}', [StudentNoteController::class, 'destroy']);

    Route::get('students/{student}/behavior', [BehaviorRecordController::class, 'index']);
    Route::post('students/{student}/behavior', [BehaviorRecordController::class, 'store']);
    Route::match(['put', 'patch'], 'behavior-records/{record}', [BehaviorRecordController::class, 'update']);
    Route::delete('behavior-records/{record}', [BehaviorRecordController::class, 'destroy']);

    // --- Student import (§2.2) ---
    Route::get('student-imports/{import}/rows', [StudentImportController::class, 'rows']);
    Route::put('student-imports/{import}/mapping', [StudentImportController::class, 'map']);
    Route::post('student-imports/{import}/commit', [StudentImportController::class, 'commit']);
    Route::post('student-imports/{import}/rows/{row}/skip', [StudentImportController::class, 'skipRow']);
    Route::apiResource('student-imports', StudentImportController::class)
        ->except(['update'])
        ->parameters(['student-imports' => 'import']);

    // --- Guardians (§2.2) ---
    Route::get('students/{student}/guardians', [StudentGuardianController::class, 'index']);
    Route::post('students/{student}/guardians', [StudentGuardianController::class, 'store']);
    Route::match(['put', 'patch'], 'students/{student}/guardians/{guardian}', [StudentGuardianController::class, 'update']);
    Route::delete('students/{student}/guardians/{guardian}', [StudentGuardianController::class, 'destroy']);
    Route::apiResource('guardians', GuardianController::class);

    // --- Subjects and teacher assignments (§2.3) ---
    Route::apiResource('subjects', SubjectController::class);
    Route::apiResource('teacher-assignments', TeacherAssignmentController::class)
        ->only(['index', 'store', 'destroy'])
        ->parameters(['teacher-assignments' => 'assignment']);

    // --- Assessment setup and grade entry (§2.3) ---
    Route::post('assessment-types/reorder', [AssessmentTypeController::class, 'reorder']);
    Route::apiResource('assessment-types', AssessmentTypeController::class)
        ->parameters(['assessment-types' => 'assessment_type']);
    Route::apiResource('rubrics', RubricController::class);
    Route::apiResource('assessments', AssessmentController::class);

    // النشر هو ما يُطلق إشعارات العلامات؛ الحفظ وحده لا يُشعر أحداً.
    Route::post('assessments/{assessment}/publish', [AssessmentController::class, 'publish']);
    Route::get('assessments/{assessment}/sections', [GradeScoreController::class, 'sections']);
    Route::get('assessments/{assessment}/scores', [GradeScoreController::class, 'index']);
    Route::post('assessments/{assessment}/scores', [GradeScoreController::class, 'store']);

    // --- Attendance (§2.4) ---
    Route::get('sections/{section}/attendance', [AttendanceController::class, 'sheet']);
    Route::post('sections/{section}/attendance', [AttendanceController::class, 'store']);
    Route::get('attendance', [AttendanceController::class, 'index']);

    Route::post('excuses/{excuse}/review', [AbsenceExcuseController::class, 'review']);
    Route::apiResource('excuses', AbsenceExcuseController::class)
        ->only(['index', 'store', 'show', 'destroy'])
        ->parameters(['excuses' => 'excuse']);

    // --- Gate attendance / NFC cards (§2.4) ---
    Route::get('students/{student}/cards', [GateAttendanceController::class, 'cards']);
    Route::post('students/{student}/cards', [GateAttendanceController::class, 'storeCard']);
    Route::delete('cards/{card}', [GateAttendanceController::class, 'revokeCard']);
    Route::get('gate/scans', [GateAttendanceController::class, 'scans']);
    Route::post('gate/scans', [GateAttendanceController::class, 'scan']);

    // --- Fees (§2.5) ---
    Route::apiResource('fee-types', FeeTypeController::class)
        ->parameters(['fee-types' => 'fee_type']);

    // --- لوحة الشرف ---
    Route::post('honor-entries/{honorEntry}/publish', [HonorEntryController::class, 'publish']);
    Route::post('honor-entries/bulk', [HonorEntryController::class, 'bulkStore']);
    Route::apiResource('honor-entries', HonorEntryController::class)
        ->only(['index', 'store', 'destroy']);

    Route::get('fees/summary', [FeePlanController::class, 'summary']);
    Route::get('students/{student}/fee-plans', [FeePlanController::class, 'forStudent']);
    Route::get('students/{student}/fee-statement', [FeePlanController::class, 'statement']);
    Route::put('fee-plans/{fee_plan}/installments', [FeePlanController::class, 'updateInstallments']);
    Route::get('fee-plans/{fee_plan}/payments', [FeePaymentController::class, 'index']);
    Route::post('fee-plans/{fee_plan}/payments', [FeePaymentController::class, 'store']);
    // الإيصال لا يُحذف؛ يُلغى بسبب ويبقى في السجل.
    Route::post('fee-payments/{payment}/void', [FeePaymentController::class, 'void']);
    Route::apiResource('fee-plans', FeePlanController::class)
        ->parameters(['fee-plans' => 'fee_plan']);

    // --- Posts and approvals (§2.6) ---
    Route::get('post-types', [PostTypeController::class, 'index']);
    Route::match(['put', 'patch'], 'post-types/{post_type}', [PostTypeController::class, 'update']);

    Route::get('posts/pending', [PostController::class, 'pending']);
    Route::post('posts/{post}/submit', [PostController::class, 'submit']);
    Route::post('posts/{post}/review', [PostController::class, 'review']);
    // Read / confirmation receipts (§ announcement engagement).
    Route::post('posts/{post}/read', [PostController::class, 'markRead']);
    Route::post('posts/{post}/confirm', [PostController::class, 'confirm']);
    Route::get('posts/{post}/receipts', [PostController::class, 'receipts']);
    Route::apiResource('posts', PostController::class);

    // --- Messaging (§2.6) ---
    Route::get('conversations/types', [ConversationController::class, 'types']);
    Route::get('conversations/{conversation}/messages', [ConversationController::class, 'messages']);
    Route::post('conversations/{conversation}/messages', [ConversationController::class, 'sendMessage']);
    Route::post('conversations/{conversation}/read', [ConversationController::class, 'markRead']);
    Route::post('conversations/{conversation}/mute', [ConversationController::class, 'toggleMute']);
    Route::match(['put', 'patch'], 'conversations/{conversation}/status', [ConversationController::class, 'updateStatus']);
    Route::match(['put', 'patch'], 'conversations/{conversation}/follow-up', [ConversationController::class, 'updateFollowUp']);
    // من يمكن مراسلته — مفتوح لكل مستخدم مسجَّل، ويعيد الحدّ الأدنى فقط
    // (لا هواتف ولا بريد) لأن الأهالي يحتاجونه ولا يجوز فتح دليل الكادر لهم.
    Route::get('messageable-staff', [MessageableController::class, 'index']);

    Route::apiResource('conversations', ConversationController::class)->only(['index', 'store', 'show']);

    // --- Attachments and gallery (§2.6) ---
    Route::get('gallery', [AttachmentController::class, 'gallery']);
    Route::get('attachments', [AttachmentController::class, 'index']);
    Route::post('attachments', [AttachmentController::class, 'store']);
    Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy']);

    // --- Calendar: a union view, no table of its own (§2.8, decision 10-a) ---
    Route::get('calendar', [CalendarController::class, 'index']);
    Route::apiResource('holidays', HolidayController::class)->except(['show']);

    // --- Report cards (§2.7) ---
    Route::post('report-cards/{report_card}/publish', [ReportCardController::class, 'publish']);
    Route::apiResource('report-cards', ReportCardController::class)
        ->parameters(['report-cards' => 'report_card']);

    // --- Notifications and preferences (§2.7) ---
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::get('notifications', [NotificationController::class, 'index']);

    Route::get('notification-settings/apps', [NotificationController::class, 'apps']);
    Route::get('notification-settings/me', [NotificationController::class, 'mySettings']);
    Route::match(['put', 'patch'], 'notification-settings/me', [NotificationController::class, 'updateMySetting']);
    Route::get('notification-settings/school', [NotificationController::class, 'schoolSettings']);
    Route::match(['put', 'patch'], 'notification-settings/school/{setting}', [NotificationController::class, 'updateSchoolSetting']);

    // --- Push registration (FCM) ---
    Route::post('device-tokens', [DeviceTokenController::class, 'store']);
    Route::delete('device-tokens', [DeviceTokenController::class, 'destroy']);

    Route::get('preferences', [UserPreferenceController::class, 'show']);
    Route::match(['put', 'patch'], 'preferences', [UserPreferenceController::class, 'update']);

    // --- Operational insights: attention list, class health, workload, conflicts, data health, setup ---
    Route::get('insights/attention', [InsightsController::class, 'attention']);
    Route::get('insights/class-health', [InsightsController::class, 'classHealth']);
    Route::get('insights/staff-workload', [InsightsController::class, 'staffWorkload']);
    Route::get('insights/schedule-conflicts', [InsightsController::class, 'scheduleConflicts']);
    Route::get('insights/data-health', [InsightsController::class, 'dataHealth']);
    Route::get('insights/setup-progress', [InsightsController::class, 'setupProgress']);

    // --- Timetable (§2.3) ---
    Route::get('schedule/days', [ScheduleSlotController::class, 'days']);
    Route::apiResource('schedule-slots', ScheduleSlotController::class)
        ->except(['show'])
        ->parameters(['schedule-slots' => 'slot']);
});
