<?php

namespace App\Enums;

enum ReportCardStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    public function label(): string
    {
        return __('report_card_statuses.'.$this->value);
    }
}
