<?php

namespace App\Enums;

/** سبب التكريم — يصنّف اللوحة ويلوّن بطاقتها في التطبيق. */
enum HonorCategory: string
{
    /** تفوّق في العلامات. */
    case Academic = 'academic';

    /** تحسّن ملحوظ — الطالب الذي قفز، لا الذي كان أصلاً في القمة. */
    case Improvement = 'improvement';

    case Behavior = 'behavior';
    case Attendance = 'attendance';
    case Participation = 'participation';
    case Other = 'other';

    public function label(): string
    {
        return __('honor_categories.'.$this->value);
    }
}
