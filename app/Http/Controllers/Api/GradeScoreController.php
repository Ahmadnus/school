<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\StoreScoresRequest;
use App\Http\Resources\AssessmentResource;
use App\Http\Resources\GradeScoreResource;
use App\Http\Resources\SectionResource;
use App\Http\Resources\StudentResource;
use App\Models\Assessment;
use App\Models\Section;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GradeScoreController extends Controller
{
    /**
     * The grade-entry grid: the roster of a section plus each student's score,
     * blank where nothing was entered yet.
     */
    public function index(Request $request, Assessment $assessment): JsonResponse
    {
        $this->authorize('view', $assessment);

        // كشف العلامات ورقة الكادر: علامات الصفّ كلّه، منشورةً وغير منشورة.
        // وليّ الأمر يرى علامات أولاده المنشورة من ملفّاتهم.
        abort_if($request->user()->role->isGuardian(), 403, __('messages.unauthorized'));

        $assessment->load('subject.grade');

        // الأستاذ يرى طلاب الشعب التي يدرّس فيها هذه المادة (أو يشرف عليها)
        // لا الصفّ كلّه.
        $sectionIds = $request->user()->reachableSectionIds($assessment->subject_id);

        $students = Student::query()
            ->ofSchool($assessment->subject->grade->school_id)
            ->when(
                $request->filled('section_id'),
                fn ($q) => $q->inSection($request->integer('section_id')),
                fn ($q) => $q->inGrade($assessment->subject->grade_id),
            )
            ->when(
                $sectionIds !== null,
                fn ($q) => $q->currentYear(fn ($e) => $e->whereIn('section_id', $sectionIds)),
            )
            ->with('currentEnrollment.section.grade')
            ->orderBy('first_name')
            ->get();

        $scores = $assessment->scores()
            ->whereIn('student_id', $students->pluck('id'))
            ->with('rubricLevel')
            ->get()
            ->keyBy('student_id');

        return response()->json([
            'data' => [
                'assessment' => new AssessmentResource($assessment),
                // `score` رقمٌ مجرَّد لا كائن. كان يحمل `GradeScoreResource`
                // كاملاً، فيقرؤه حقل الإدخال في التطبيق نصّاً فيظهر للأستاذ
                // `{id: 41, assessment_id: 3, score: 85.00, ...}` مكان «85».
                // والسجلّ كلّه يبقى متاحاً في `record`، على غرار كشف الحضور.
                'rows' => $students->map(function (Student $student) use ($scores) {
                    $score = $scores->get($student->id);

                    return [
                        'student' => new StudentResource($student),
                        // بلا الأصفار الزائدة: خزانة `decimal:2` تُعيد "85.00"،
                        // والأستاذ كتب «85» فيجب أن يرى «85».
                        'score' => self::plainScore($score?->score),
                        'record' => $score ? new GradeScoreResource($score) : null,
                    ];
                })->values(),
            ],
        ]);
    }

    /**
     * Saves the whole grid in one call. A null score clears the cell rather than
     * failing, because saving a partly-filled class is allowed.
     */
    public function store(StoreScoresRequest $request, Assessment $assessment): JsonResponse
    {
        $this->authorize('score', $assessment);

        // أن يُسنَد إلى الأستاذ تدريس المادة لا يكفي: يجب أن يكون كلّ طالبٍ
        // في الطلب من شعبةٍ يدرّس فيها هذه المادة. وإلّا كتب أستاذ عربي التاسع
        // في شعبةٍ علاماتِ طالبٍ في شعبةٍ أخرى لا يدرّسها.
        $sectionIds = $request->user()->reachableSectionIds($assessment->subject_id);

        if ($sectionIds !== null) {
            $studentIds = collect($request->input('scores'))->pluck('student_id')->unique();
            $reachable = Student::query()
                ->whereKey($studentIds)
                ->currentYear(fn ($e) => $e->whereIn('section_id', $sectionIds))
                ->count();

            abort_if($reachable !== $studentIds->count(), 403, __('messages.unauthorized'));
        }

        DB::transaction(function () use ($request, $assessment) {
            foreach ($request->input('scores') as $row) {
                $assessment->scores()->updateOrCreate(
                    ['student_id' => $row['student_id']],
                    [
                        'score' => $row['score'] ?? null,
                        'rubric_level_id' => $row['rubric_level_id'] ?? null,
                        'entered_at' => now(),
                        'entered_by' => $request->user()->id,
                    ],
                );
            }
        });

        return response()->json([
            'message' => __('messages.score.saved'),
            'data' => GradeScoreResource::collection(
                $assessment->scores()->with('rubricLevel')->get(),
            ),
        ]);
    }

    /** Sections available for this assessment's grade, in the subject's year. */
    public function sections(Assessment $assessment): JsonResponse
    {
        $this->authorize('view', $assessment);

        $assessment->load('subject.term');

        $sections = Section::query()
            ->where('grade_id', $assessment->subject->grade_id)
            ->where('academic_year_id', $assessment->subject->term->academic_year_id)
            ->withCount('enrollments')
            ->ordered()
            ->get();

        return response()->json([
            'data' => SectionResource::collection($sections),
        ]);
    }

    /**
     * الدرجة كما يجب أن تظهر في حقل الإدخال: رقمٌ بلا أصفار عشرية زائدة.
     *
     * تُعاد نصّاً لا float: `85.5` تبقى `"85.5"`، و`85.00` تصير `"85"`، ولا
     * يتدخّل تمثيل العائم في ما يقرؤه الأستاذ.
     */
    private static function plainScore(mixed $score): ?string
    {
        if ($score === null) {
            return null;
        }

        $text = (string) $score;

        return str_contains($text, '.')
            ? rtrim(rtrim($text, '0'), '.')
            : $text;
    }
}
