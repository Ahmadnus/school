<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\FeePlan;
use App\Models\LedgerEntry;
use App\Models\SalaryPayment;
use App\Models\Student;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * يبني تقارير Excel لإدارة المعهد: طلاب، أقساط، قيود محاسبية، رواتب، تدقيق.
 *
 * كل تقرير يُجزَّأ عند القراءة (`chunk`) ويُكتب سطراً سطراً، فلا يُحمَّل جدول
 * كامل في الذاكرة. المبالغ تُكتب أرقاماً (قابلة للجمع في Excel) مأخوذة من
 * الفلس لا من float حسابي: التحويل في آخر خطوة عرض فقط.
 */
class ReportExporter
{
    public const TYPES = ['students', 'fees', 'ledger', 'payroll', 'audit'];

    /**
     * @param  array{from?: ?string, to?: ?string, month?: ?string}  $options
     * @return string مسار الملف المؤقت
     */
    public static function build(string $type, int $schoolId, array $options = []): string
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("unknown report: {$type}");
        }

        $path = tempnam(sys_get_temp_dir(), 'kader-report-').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);

        [$headers, $each] = match ($type) {
            'students' => self::students($schoolId),
            'fees' => self::fees($schoolId),
            'ledger' => self::ledger($schoolId, $options),
            'payroll' => self::payroll($schoolId, $options),
            'audit' => self::audit($schoolId, $options),
        };

        $writer->addRow(Row::fromValues($headers));
        $each(fn (array $row) => $writer->addRow(Row::fromValues($row)));
        $writer->close();

        return $path;
    }

    /** @return array{0: list<string>, 1: callable} */
    private static function students(int $schoolId): array
    {
        $headers = [__('exports.col.number'), __('exports.col.name'), __('exports.col.gender'),
            __('exports.col.phone'), __('exports.col.status'), __('exports.col.grade'), __('exports.col.section')];

        return [$headers, function (callable $emit) use ($schoolId) {
            Student::query()->where('school_id', $schoolId)
                ->with('currentEnrollment.section.grade')
                ->orderBy('id')
                ->chunk(500, function ($students) use ($emit) {
                    foreach ($students as $s) {
                        $section = $s->currentEnrollment?->section;
                        $emit([
                            $s->student_number, $s->full_name, $s->gender?->value, $s->phone,
                            $s->status?->value, $section?->grade?->name, $section?->name,
                        ]);
                    }
                });
        }];
    }

    /** @return array{0: list<string>, 1: callable} */
    private static function fees(int $schoolId): array
    {
        $headers = [__('exports.col.number'), __('exports.col.name'), __('exports.col.net'),
            __('exports.col.paid'), __('exports.col.remaining'), __('exports.col.state')];

        return [$headers, function (callable $emit) use ($schoolId) {
            FeePlan::query()->ofSchool($schoolId)
                ->with(['student', 'payments', 'installments'])
                ->orderBy('id')
                ->chunk(300, function ($plans) use ($emit) {
                    foreach ($plans as $p) {
                        $emit([
                            $p->student?->student_number, $p->student?->full_name,
                            self::num($p->netAmount()->toDecimal()), self::num($p->paidAmount()->toDecimal()),
                            self::num($p->remainingAmount()->toDecimal()), $p->paymentState()->value,
                        ]);
                    }
                });
        }];
    }

    /** @return array{0: list<string>, 1: callable} */
    private static function ledger(int $schoolId, array $o): array
    {
        $headers = [__('exports.col.date'), __('exports.col.kind'), __('exports.col.category'),
            __('exports.col.amount'), __('exports.col.method'), __('exports.col.description'), __('exports.col.voided')];

        return [$headers, function (callable $emit) use ($schoolId, $o) {
            LedgerEntry::query()->where('school_id', $schoolId)
                ->when($o['from'] ?? null, fn ($q, $v) => $q->whereDate('entry_date', '>=', $v))
                ->when($o['to'] ?? null, fn ($q, $v) => $q->whereDate('entry_date', '<=', $v))
                ->with('category')->orderBy('entry_date')->orderBy('id')
                ->chunk(500, function ($rows) use ($emit) {
                    foreach ($rows as $e) {
                        $emit([
                            $e->entry_date?->toDateString(), $e->kind, $e->category?->name,
                            self::num($e->amount()->toDecimal()), $e->method->value, $e->description,
                            $e->isVoided() ? __('exports.yes') : '',
                        ]);
                    }
                });
        }];
    }

    /** @return array{0: list<string>, 1: callable} */
    private static function payroll(int $schoolId, array $o): array
    {
        $month = $o['month'] ?? now()->format('Y-m');
        $headers = [__('exports.col.name'), __('exports.col.month'), __('exports.col.base'),
            __('exports.col.bonus'), __('exports.col.deduction'), __('exports.col.net'), __('exports.col.date')];

        return [$headers, function (callable $emit) use ($schoolId, $month) {
            SalaryPayment::query()->where('school_id', $schoolId)->where('month', $month)
                ->whereNull('voided_at')->with('user')->orderBy('id')
                ->chunk(500, function ($rows) use ($emit) {
                    foreach ($rows as $p) {
                        $emit([
                            $p->user?->name, $p->month,
                            self::num($p->base_minor->toDecimal()), self::num($p->bonus_minor->toDecimal()),
                            self::num($p->deduction_minor->toDecimal()), self::num($p->net_minor->toDecimal()),
                            $p->paid_on?->toDateString(),
                        ]);
                    }
                });
        }];
    }

    /** @return array{0: list<string>, 1: callable} */
    private static function audit(int $schoolId, array $o): array
    {
        $headers = [__('exports.col.date'), __('exports.col.user'), __('exports.col.event'),
            __('exports.col.type'), __('exports.col.description'), __('exports.col.old'), __('exports.col.new')];

        return [$headers, function (callable $emit) use ($schoolId, $o) {
            AuditLog::query()->where('school_id', $schoolId)
                ->when($o['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
                ->when($o['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'))
                ->with('user:id,name')->orderBy('id')
                ->chunk(500, function ($rows) use ($emit) {
                    foreach ($rows as $l) {
                        $emit([
                            $l->created_at?->toDateTimeString(), $l->user?->name,
                            __('audit.events.'.$l->event), __('audit.models.'.$l->auditable_type), $l->label,
                            $l->old_values ? json_encode($l->old_values, JSON_UNESCAPED_UNICODE) : '',
                            $l->new_values ? json_encode($l->new_values, JSON_UNESCAPED_UNICODE) : '',
                        ]);
                    }
                });
        }];
    }

    /** المبلغ رقماً في الخلية (لا نصاً) كي يُجمع في Excel. */
    private static function num(string $decimal): float
    {
        return (float) $decimal;
    }
}
