<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * تصدير Excel. خطوتان لأن تطبيق الجوال يفتح الملف عبر المتصفّح بلا ترويسة
 * توثيق:
 *   ١) `POST /api/exports` (للإدارة) يُرجع رابطاً **موقّعاً** صالحاً ٥ دقائق.
 *   ٢) `GET /api/exports/download` يتحقق من التوقيع ويبني الملف.
 * مدرسة المستخدم داخل الرابط الموقّع فلا يمكن تبديلها دون إبطال التوقيع.
 */
class ExportController extends Controller
{
    public function link(Request $request): JsonResponse
    {
        abort_unless($request->user()->role->isAdministrative(), 403);

        $data = $request->validate([
            'type' => ['required', Rule::in(ReportExporter::TYPES)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $url = URL::temporarySignedRoute('exports.download', now()->addMinutes(5), [
            'type' => $data['type'],
            'school' => $request->user()->school_id,
            ...array_filter([
                'from' => $data['from'] ?? null,
                'to' => $data['to'] ?? null,
                'month' => $data['month'] ?? null,
            ]),
        ]);

        return response()->json(['data' => ['url' => $url]]);
    }

    public function download(Request $request): BinaryFileResponse
    {
        $type = (string) $request->query('type');
        abort_unless(in_array($type, ReportExporter::TYPES, true), 404);

        $path = ReportExporter::build($type, (int) $request->query('school'), [
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'month' => $request->query('month'),
        ]);

        return response()
            ->download($path, "kader-{$type}-".now()->format('Ymd').'.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }
}
