<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\StoreTasmiRequest;
use App\Http\Resources\StudentResource;
use App\Models\Assessment;
use App\Models\Section;
use App\Models\Subject;
use App\Services\TasmiNotifier;
use App\Services\TasmiSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * التسميع: الصفّ ← الشعبة ← المادة ← من شارك ← درجته.
 *
 * يجلس على `assessments` و`grades_scores` القائمين ولا يبني جدولاً ثانياً؛
 * الشرح كلّه في `TasmiSession`. وهذا المتحكّم واجهةٌ رقيقة: لا قاعدة عمل فيه،
 * ولا شرطَ صلاحية — الأولى في الخدمة والثانية في `AssessmentPolicy`.
 */
class TasmiController extends Controller
{
    /** جلسات التسميع، تُرشَّح بالشعبة والمادة والتاريخ. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Assessment::class);

        $schoolId = $request->user()->school_id;
        $type = TasmiSession::type($schoolId);

        $sessions = Assessment::query()
            ->ofSchool($schoolId)
            ->where('assessment_type_id', $type->id)
            ->when(
                $request->filled('section_id'),
                fn ($q) => $q->where('section_id', $request->integer('section_id')),
            )
            ->when(
                $request->filled('subject_id'),
                fn ($q) => $q->where('subject_id', $request->integer('subject_id')),
            )
            ->when($request->filled('from'), fn ($q) => $q->whereDate('held_on', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('held_on', '<=', $request->date('to')))
            ->with(['subject.grade', 'section.grade', 'creator'])
            ->withCount(['scores as participants_count' => fn ($q) => $q->whereNotNull('score')])
            ->orderByDesc('held_on')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return response()->json([
            'data' => collect($sessions->items())->map(fn (Assessment $a) => $this->summary($a)),
            'meta' => [
                'current_page' => $sessions->currentPage(),
                'last_page' => $sessions->lastPage(),
                'total' => $sessions->total(),
            ],
        ]);
    }

    /**
     * شبكة الاختيار: طلاب الشعبة، ومن منهم مؤشَّر بدرجته.
     *
     * تُنادى قبل وجود جلسة أيضاً (بلا `held_on` سابقة): فتعود القائمة كلّها
     * غير مؤشَّرة، وهو ما يريده الأستاذ أوّل مرّة.
     */
    public function roster(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject_id' => ['required', 'integer'],
            'section_id' => ['required', 'integer'],
            'held_on' => ['nullable', 'date'],
            // اختياريّ، لكنّه إن أُرسل فُحص — كما في الحضور.
            'grade_id' => ['nullable', 'integer'],
        ]);

        $subject = Subject::query()->with('grade')->findOrFail($data['subject_id']);
        $section = Section::query()->with('grade')->findOrFail($data['section_id']);

        $this->authorize('view', $section);

        if (isset($data['grade_id']) && (int) $section->grade_id !== (int) $data['grade_id']) {
            return response()->json([
                'message' => __('messages.tasmi.section_not_in_grade'),
            ], 422);
        }

        if ($section->grade_id !== $subject->grade_id) {
            return response()->json([
                'message' => __('messages.tasmi.section_not_in_grade'),
            ], 422);
        }

        $heldOn = isset($data['held_on']) ? Carbon::parse($data['held_on']) : Carbon::today();

        // لا تُنشأ جلسة عند مجرّد العرض: القراءة لا تكتب.
        $assessment = Assessment::query()
            ->where('subject_id', $subject->id)
            ->where('name', TasmiSession::nameFor($section, $heldOn))
            ->first() ?? new Assessment;

        $rows = TasmiSession::roster($assessment, $section);

        return response()->json([
            'data' => [
                'assessment_id' => $assessment->exists ? $assessment->id : null,
                'held_on' => $heldOn->toDateString(),
                'max_score' => $assessment->exists
                    ? TasmiSession::plain($assessment->max_score)
                    : (string) TasmiSession::DEFAULT_MAX,
                'subject' => ['id' => $subject->id, 'name' => $subject->name],
                'section' => ['id' => $section->id, 'name' => $section->name],
                'rows' => $rows->map(fn (array $row) => [
                    'student' => new StudentResource($row['student']),
                    'participating' => $row['participating'],
                    'score' => $row['score'],
                ])->values(),
            ],
        ]);
    }

    /**
     * يحفظ الجلسة ويُشعر أولياء من تغيّرت درجته.
     *
     * الإشعار على الحفظ لا على «نشر» منفصل، بخلاف الامتحانات: التسميع حدثٌ
     * يوميّ صغير يُدخَل مرّةً واحدة وينتهي، ولا مرحلةَ «عمل قيد التنفيذ» فيه
     * تُبرّر خطوةً ثانية. ومن حُفظت درجته بلا تغيير لا يُشعَر ثانيةً.
     */
    public function store(StoreTasmiRequest $request): JsonResponse
    {
        $data = $request->validated();

        $subject = Subject::query()->with('grade')->findOrFail($data['subject_id']);
        $section = Section::query()->with('grade')->findOrFail($data['section_id']);

        // الصلاحية على المادة: الأستاذ يسمّع ما أُسند إليه وحده.
        $this->authorize('score', $this->probe($subject));

        $assessment = TasmiSession::open(
            $subject,
            $section,
            Carbon::parse($data['held_on']),
            $request->user(),
            isset($data['max_score']) ? (float) $data['max_score'] : null,
        );

        $result = TasmiSession::record($assessment, $data['entries'], $request->user());

        $delivery = TasmiNotifier::recorded($assessment->fresh(['subject', 'section.grade', 'type']), $result['changed']);

        return response()->json([
            'message' => __('messages.tasmi.saved', ['count' => $result['saved']]),
            'data' => [
                ...$this->summary($assessment->fresh(['subject.grade', 'section.grade', 'creator'])),
                'saved' => $result['saved'],
                'removed' => $result['removed'],
                // يُقال صراحةً من لم يصله شيء، ولا يُحسَب مُرسَلاً.
                'notified' => $delivery['sent'],
                'not_signed_in' => $delivery['not_signed_in'],
                'without_guardian' => $delivery['without_guardian'],
            ],
        ]);
    }

    /** جلسة واحدة بمشاركيها ودرجاتهم. */
    public function show(Assessment $assessment): JsonResponse
    {
        $this->authorize('view', $assessment);

        $assessment->load(['subject.grade', 'section.grade', 'type', 'creator']);

        abort_unless(TasmiSession::isTasmi($assessment), 404);

        $rows = $assessment->scores()
            ->whereNotNull('score')
            ->with('student')
            ->get()
            ->map(fn ($score) => [
                'student' => new StudentResource($score->student),
                'score' => TasmiSession::plain($score->score),
            ])
            ->values();

        return response()->json([
            'data' => [...$this->summary($assessment), 'rows' => $rows],
        ]);
    }

    /** حذف جلسة كاملة — ودرجاتها تذهب معها. */
    public function destroy(Assessment $assessment): JsonResponse
    {
        $this->authorize('update', $assessment);

        $assessment->load('type');
        abort_unless(TasmiSession::isTasmi($assessment), 404);

        $assessment->delete();

        return response()->json(['message' => __('messages.tasmi.deleted')]);
    }

    /**
     * كائن تقييمٍ غير محفوظ، لتُسأل عنه السياسة قبل إنشاء الجلسة.
     *
     * `AssessmentPolicy::score` تفحص المادة وإسناد الأستاذ إليها، ولا تحتاج
     * صفّاً في قاعدة البيانات. فلا تُنشأ جلسة ثمّ يُرفَض صاحبها.
     */
    private function probe(Subject $subject): Assessment
    {
        $assessment = new Assessment(['subject_id' => $subject->id]);
        $assessment->setRelation('subject', $subject);

        return $assessment;
    }

    private function summary(Assessment $assessment): array
    {
        return [
            'id' => $assessment->id,
            'name' => $assessment->name,
            'held_on' => $assessment->held_on?->toDateString(),
            'max_score' => TasmiSession::plain($assessment->max_score),
            'participants_count' => $assessment->participants_count
                ?? $assessment->scores()->whereNotNull('score')->count(),
            'subject' => $assessment->subject ? [
                'id' => $assessment->subject->id,
                'name' => $assessment->subject->name,
                'grade' => $assessment->subject->grade?->name,
            ] : null,
            'section' => $assessment->section ? [
                'id' => $assessment->section->id,
                'name' => $assessment->section->name,
                'grade' => $assessment->section->grade?->name,
            ] : null,
            'created_by' => $assessment->creator?->full_name,
        ];
    }
}
