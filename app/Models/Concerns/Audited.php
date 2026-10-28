<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * يسجّل إنشاء النموذج وتعديله وحذفه في `audit_logs` بقيمه القديمة والجديدة.
 *
 * التسجيل لا يُسقط العملية الأصلية أبداً: أي فشل فيه يُبتلع ويُكتب في سجلّ
 * الأخطاء، فالبيانات الحقيقية أهمّ من سطر تدقيق.
 *
 * حقول سرّية (كلمات السر والرموز) لا تُكتب قيمها؛ يظهر فقط أنها تغيّرت.
 */
trait Audited
{
    /** @var list<string> */
    private static array $auditRedacted = ['password', 'remember_token', 'token', 'code'];

    public static function bootAudited(): void
    {
        static::created(fn (Model $m) => self::audit($m, 'created', [], $m->getAttributes()));

        static::updated(function (Model $m) {
            $changes = $m->getChanges();
            unset($changes['updated_at']);
            // حقلٌ غاب من النموذج ثم كُتب `null` ليس تغييراً حقيقياً.
            $changes = array_filter(
                $changes,
                fn ($value, $key) => ($m->getOriginal($key) ?? null) !== $value,
                ARRAY_FILTER_USE_BOTH,
            );
            if ($changes === []) {
                return;
            }
            $old = array_intersect_key($m->getOriginal(), $changes);
            self::audit($m, 'updated', $old, $changes);
        });

        static::deleted(fn (Model $m) => self::audit($m, 'deleted', $m->getAttributes(), []));
    }

    /** وصف قصير يُعرض في القائمة؛ تتجاوزه النماذج عند الحاجة. */
    public function auditLabel(): ?string
    {
        foreach (['name', 'full_name', 'title', 'receipt_number', 'student_number'] as $field) {
            $value = $this->getAttribute($field);
            if (is_string($value) && $value !== '') {
                return mb_substr($value, 0, 120);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private static function audit(Model $m, string $event, array $old, array $new): void
    {
        try {
            $actor = Auth::user();
            $schoolId = $m->getAttribute('school_id') ?? $actor?->school_id;
            $request = app()->runningInConsole() ? null : request();

            AuditLog::create([
                'school_id' => $schoolId,
                'user_id' => $actor?->getAuthIdentifier(),
                'event' => $event,
                'auditable_type' => class_basename($m),
                'auditable_id' => $m->getKey(),
                'label' => method_exists($m, 'auditLabel') ? $m->auditLabel() : null,
                'old_values' => self::scrub($old) ?: null,
                'new_values' => self::scrub($new) ?: null,
                'ip' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function scrub(array $values): array
    {
        foreach (self::$auditRedacted as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = '***';
            }
        }
        unset($values['created_at'], $values['updated_at']);

        return $values;
    }
}
