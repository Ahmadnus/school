<?php

namespace App\Services\Timetable;

/**
 * حصّةٌ **واحدة** مطلوبة: إسنادٌ مُفرَدٌ إلى عدد حصصه.
 *
 * الإسناد يقول «أربع حصص رياضيات للتاسع أ»، والمولّد يجدول حصّةً حصّة. ففُرِدت
 * هنا: أربع مطالب من نسخةٍ واحدة، لكلٍّ ترتيبُه (`index`) حتى يبقى الترتيب
 * ثابتاً بين تشغيلين.
 */
final class LessonRequirement
{
    /** @var list<array{day:int,range:TimeRange,period:int}> الأماكن الممكنة. */
    public array $candidates = [];

    public function __construct(
        public readonly int $staffId,
        public readonly int $sectionId,
        public readonly int $subjectId,
        public readonly int $lessonMinutes,
        public readonly int $index,
        public readonly int $totalForAssignment,
    ) {}

    public function key(): string
    {
        return "{$this->sectionId}:{$this->subjectId}:{$this->staffId}:{$this->index}";
    }

    /** مفتاح الإسناد بلا ترتيب الحصّة — حصص المادة نفسها في الشعبة نفسها. */
    public function assignmentKey(): string
    {
        return "{$this->sectionId}:{$this->subjectId}:{$this->staffId}";
    }
}
