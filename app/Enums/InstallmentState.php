<?php

namespace App\Enums;

/** حالة قسط واحد داخل الخطة، كما تظهر في جدول الأقساط. */
enum InstallmentState: string
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';

    public function label(): string
    {
        return __('installment_states.'.$this->value);
    }
}
