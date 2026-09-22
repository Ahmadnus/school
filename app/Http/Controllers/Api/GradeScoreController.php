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

        $assessment->load('subject.grade');

        $students = Student::query()
            ->ofSchool($assessment->subject->grade->school_id)
            ->when(
                $request->filled('section_id'),
                fn ($q) => $q->inSection($request->integer('section_id')),
                fn ($q) => $q->inGrade($assessment->subject->grade_id),
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
                'rows' => $students->map(fn (Student $student) => [
                    'student' => new StudentResource($student),
                    'score' => $scores->has($student->id)
                        ? new GradeScoreResource($scores[$student->id])
                        : null,
                ])->values(),
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
}
