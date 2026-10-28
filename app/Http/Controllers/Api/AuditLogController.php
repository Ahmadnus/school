<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * سجلّ التدقيق — قراءة فقط، للإدارة فقط، ضمن مدرسة المستخدم.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->role->isAdministrative(), 403);

        $data = $request->validate([
            'type' => ['nullable', 'string', 'max:64'],
            'target_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'event' => ['nullable', 'in:created,updated,deleted'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $logs = AuditLog::query()
            ->where('school_id', $request->user()->school_id)
            ->when($data['type'] ?? null, fn ($q, $v) => $q->where('auditable_type', $v))
            ->when($data['target_id'] ?? null, fn ($q, $v) => $q->where('auditable_id', $v))
            ->when($data['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($data['event'] ?? null, fn ($q, $v) => $q->where('event', $v))
            ->when($data['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($data['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'))
            ->with('user:id,name')
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 30);

        return AuditLogResource::collection($logs);
    }
}
