<?php

namespace App\Enums;

enum GateScanResult: string
{
    case Accepted = 'accepted';
    case UnknownCard = 'unknown_card';
    case RevokedCard = 'revoked_card';
    case Duplicate = 'duplicate';

    public function label(): string
    {
        return __('gate_scan_results.'.$this->value);
    }
}
