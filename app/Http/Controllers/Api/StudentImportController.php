<?php

namespace App\Http\Controllers\Api;

use App\Enums\ImportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Import\MapStudentImportRequest;
use App\Http\Requests\Import\StoreStudentImportRequest;
use App\Http\Resources\StudentImportResource;
use App\Http\Resources\StudentImportRowResource;
use App\Models\StudentImport;
use App\Models\StudentImportRow;
use App\Services\SpreadsheetReader;
use App\Services\StudentImportProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class StudentImportController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', StudentImport::class);

        $imports = StudentImport::query()
            ->ofSchool($request->user()->school_id)
            ->with(['section.grade', 'academicYear'])
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return StudentImportResource::collection($imports);
    }

    /**
     * Step 1 — upload. The file is parsed into rows straight away so the
     * mapping screen can open with the real headers and a first guess.
     */
    public function store(StoreStudentImportRequest $request): JsonResponse
    {
        $this->authorize('create', StudentImport::class);

        $file = $request->file('file');
        $path = $file->store('imports/students', 'local');

        $import = StudentImport::create([
            'school_id' => $request->user()->school_id,
            'academic_year_id' => $request->integer('academic_year_id'),
            'section_id' => $request->integer('section_id'),
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'created_by' => $request->user()->id,
        ]);

        try {
            $parsed = SpreadsheetReader::read(
                Storage::disk('local')->path($path),
                $file->getClientOriginalExtension() ?: 'csv',
            );
        } catch (RuntimeException $e) {
            $import->update(['status' => ImportStatus::Failed, 'error' => $e->getMessage()]);

            return response()->json([
                'message' => __('messages.import.'.$e->getMessage()),
            ], 422);
        }

        DB::transaction(function () use ($import, $parsed) {
            foreach ($parsed['rows'] as $index => $cells) {
                $import->rows()->create([
                    // Row 1 is the header, so data starts at 2.
                    'row_number' => $index + 2,
                    'raw' => $cells,
                ]);
            }

            $import->update([
                'headers' => $parsed['headers'],
                'mapping' => SpreadsheetReader::guessMapping($parsed['headers']),
                'rows_count' => count($parsed['rows']),
            ]);
        });

        return response()->json([
            'message' => __('messages.import.uploaded'),
            'data' => new StudentImportResource($import->fresh(['section.grade', 'academicYear'])),
        ], 201);
    }

    public function show(StudentImport $import): StudentImportResource
    {
        $this->authorize('view', $import);

        return new StudentImportResource($import->load(['section.grade', 'academicYear']));
    }

    /** Step 2 — column mapping, which validates every row. */
    public function map(MapStudentImportRequest $request, StudentImport $import): JsonResponse
    {
        $this->authorize('update', $import);

        if ($import->isCommitted()) {
            return response()->json(['message' => __('messages.import.already_committed')], 422);
        }

        $import = StudentImportProcessor::applyMapping($import, $request->input('mapping'));

        return response()->json([
            'message' => __('messages.import.mapped'),
            'data' => new StudentImportResource($import),
        ]);
    }

    /** Step 3 — the review list, filterable by row status. */
    public function rows(Request $request, StudentImport $import): AnonymousResourceCollection
    {
        $this->authorize('view', $import);

        $rows = $import->rows()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->paginate($request->integer('per_page', 50))
            ->withQueryString();

        return StudentImportRowResource::collection($rows);
    }

    /** Exclude one row from the commit. */
    public function skipRow(StudentImport $import, StudentImportRow $row): JsonResponse
    {
        $this->authorize('update', $import);

        abort_unless($row->student_import_id === $import->id, 404);

        StudentImportProcessor::skip($row);

        return response()->json([
            'message' => __('messages.import.row_skipped'),
            'data' => new StudentImportRowResource($row->fresh()),
        ]);
    }

    /** Step 4 — commit: students, enrollments and guardian links are written. */
    public function commit(StudentImport $import): JsonResponse
    {
        $this->authorize('update', $import);

        if ($import->isCommitted()) {
            return response()->json(['message' => __('messages.import.already_committed')], 422);
        }

        if ($import->status !== ImportStatus::Mapped) {
            return response()->json(['message' => __('messages.import.not_mapped')], 422);
        }

        $import = StudentImportProcessor::commit($import);

        return response()->json([
            'message' => __('messages.import.committed', ['count' => $import->imported_count]),
            'data' => new StudentImportResource($import->load(['section.grade', 'academicYear'])),
        ]);
    }

    /** Discarding an uncommitted import also drops the uploaded file. */
    public function destroy(StudentImport $import): JsonResponse
    {
        $this->authorize('delete', $import);

        Storage::disk('local')->delete($import->path);
        $import->delete();

        return response()->json(['message' => __('messages.import.deleted')]);
    }
}
