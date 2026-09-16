<?php

namespace App\Enums;

enum ImportStatus: string
{
    case Uploaded = 'uploaded';
    case Mapped = 'mapped';
    case Committed = 'committed';
    case Failed = 'failed';

    public function label(): string
    {
        return __('import_statuses.'.$this->value);
    }
}
