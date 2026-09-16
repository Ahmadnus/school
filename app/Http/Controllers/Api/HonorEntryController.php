<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Honor\BulkStoreHonorEntryRequest;
use App\Http\Requests\Honor\StoreHonorEntryRequest;
use App\Http\Resources\HonorEntryResource;
use App\Models\AcademicYear;
use App\Models\HonorEntry;
use App\Models\Student;
use App\Services\HonorNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class HonorEntryController extends Controller
{
    /**
     * لوحة الشرف. للأهالي والطلاب تعرض المنشور فقط؛ الكادر وحده يرى مسوّداته.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', HonorEntry::class);

        $isStaff = ! $request->user()->role->isGuardian();

        $entries = HonorEntry::query()
            ->ofSchool($request->user()->school_id)
            ->when(
                ! ($isStaff && $request->boolean('include_drafts')),
                fn ($q) => $q->published(),
            )
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when($request->filled('subject_id'), fn ($q) => $q->where('subject_id', $request->integer('subject_id')))
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->when(
                $request->filled('academic_year_id'),
                fn ($q) => $q->where('academic_year_id', $request->integer('academic_year_id')),
            )
            ->with([
                'student.currentEnrollment.section.grade',
                'subject',
                'awarder',
            ])
            ->orderByDesc('published_at')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return HonorEntryResource::collection($entries);
    }

    public function store(StoreHonorEntryRequest $request): JsonResponse
    {
        $this->authorize('create', HonorEntry::class);

        $student = Student::findOrFail($request->integer('student_id'));

        $entry = HonorEntry::create([
            'school_id' => $student->school_id,
            'student_id' => $student->id,
            'academic_year_id' => AcademicYear::currentFor($student->school_id)?->id,
            'subject_id' => $request->input('subject_id'),
            'awarded_by' => $request->user()->id,
            'category' => $request->input('category'),
            'reason' => $request->input('reason'),
            'published_at' => $request->boolean('publish', true) ? now() : null,
        ]);

        if ($entry->isPublished()) {
            HonorNotifier::published($entry);
        }

        return response()->json([
            'message' => __('messages.honor.created'),
            'data' => new HonorEntryResource(
                $entry->load(['student.currentEnrollment.section.grade', 'subject', 'awarder']),
            ),
        ], 201);
    }

    /**
     * تكريم دفعة بسبب واحد — «الأوائل الثلاثة في كل شعبة».
     *
     * الإنشاء داخل معاملة: إمّا أن تُكتب الدفعة كلها أو لا يُكتب شيء،
     * لئلّا تُكرّم نصف الشعبة ويُترك النصف الآخر. أمّا الإشعارات فتُرسل **بعد**
     * نجاح المعاملة لا داخلها — وإلاّ وصلت تهنئة عن تكريم ارتدّ.
     *
     * الطالب المكرّم سابقاً بنفس السبب والمادة يُتخطّى بصمت (قيد التفرّد
     * في الجدول)، فإعادة إرسال الدفعة لا تكسر ولا تُكرّر التهنئة.
     */
    public function bulkStore(BulkStoreHonorEntryRequest $request): JsonResponse
    {
        $this->authorize('create', HonorEntry::class);

        $students = Student::query()
            ->whereIn('id', $request->input('student_ids'))
            ->get();

        $publish = $request->boolean('publish', true);

        $created = DB::transaction(function () use ($students, $request, $publish) {
            $made = [];

            foreach ($students as $student) {
                $exists = HonorEntry::query()
                    ->where('student_id', $student->id)
                    ->where('subject_id', $request->input('subject_id'))
                    ->where('awarded_by', $request->user()->id)
                    ->where('reason', $request->input('reason'))
                    ->exists();

                if ($exists) {
                    continue;
                }

                $made[] = HonorEntry::create([
                    'school_id' => $student->school_id,
                    'student_id' => $student->id,
                    'academic_year_id' => AcademicYear::currentFor($student->school_id)?->id,
                    'subject_id' => $request->input('subject_id'),
                    'awarded_by' => $request->user()->id,
                    'category' => $request->input('category'),
                    'reason' => $request->input('reason'),
                    'published_at' => $publish ? now() : null,
                ]);
            }

            return $made;
        });

        HonorNotifier::publishedBatch($created);

        $entries = HonorEntry::query()
            ->whereIn('id', array_map(fn (HonorEntry $e) => $e->id, $created))
            ->with(['student.currentEnrollment.section.grade', 'subject', 'awarder'])
            ->get();

        return response()->json([
            'message' => __('messages.honor.bulk_created', ['count' => $entries->count()]),
            'skipped' => $students->count() - $entries->count(),
            'data' => HonorEntryResource::collection($entries),
        ], 201);
    }

    /** نشر مسوّدة. النشر هو ما يُطلق الإشعارات، ولا يتكرّر. */
    public function publish(HonorEntry $honorEntry): JsonResponse
    {
        $this->authorize('update', $honorEntry);

        if ($honorEntry->isPublished()) {
            return response()->json(['message' => __('messages.honor.already_published')], 422);
        }

        $honorEntry->forceFill(['published_at' => now()])->save();

        HonorNotifier::published($honorEntry);

        return response()->json([
            'message' => __('messages.honor.published'),
            'data' => new HonorEntryResource(
                $honorEntry->load(['student.currentEnrollment.section.grade', 'subject', 'awarder']),
            ),
        ]);
    }

    public function destroy(HonorEntry $honorEntry): JsonResponse
    {
        $this->authorize('delete', $honorEntry);

        $honorEntry->delete();

        return response()->json(['message' => __('messages.honor.deleted')]);
    }
}
