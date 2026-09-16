<?php

namespace App\Enums;

enum PaymentState: string
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';

    public function label(): string
    {
        return __('payment_states.'.$this->value);
    }
}
