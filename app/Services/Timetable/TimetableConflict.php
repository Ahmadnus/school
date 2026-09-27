<?php

namespace App\Services\Timetable;

/**
 * سببُ استحالةٍ واحد، مكتوبٌ ليُقرأ ويُعالَج.
 *
 * «تعذّر توليد الجدول» جملةٌ لا تُفيد أحداً. المدير يحتاج أن يعرف: **من**
 * سبّب الاستحالة، و**كم** ينقصه، و**ما** الذي يغيّره ليصير الجدول ممكناً.
 * فلكلّ سبب: مفتاحُ نوعٍ تُترجمه الشاشة، وأسماءُ من يخصّه، والأرقام.
 */
final readonly class TimetableConflict
{
    public function __construct(
        public string $code,
        public array $context = [],
        public ?int $staffId = null,
        public ?int $sectionId = null,
        public ?int $subjectId = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'code' => $this->code,
            'message' => __('timetable.conflict.'.$this->code, $this->context),
            'context' => $this->context,
            'staff_id' => $this->staffId,
            'section_id' => $this->sectionId,
            'subject_id' => $this->subjectId,
        ], fn ($value) => $value !== null && $value !== []);
    }
}
