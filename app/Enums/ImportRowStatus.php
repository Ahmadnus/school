<?php

namespace App\Enums;

enum ImportRowStatus: string
{
    case Pending = 'pending';
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Imported = 'imported';
    case Skipped = 'skipped';

    public function label(): string
    {
        return __('import_row_statuses.'.$this->value);
    }
}
