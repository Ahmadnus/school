<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';
    case Card = 'card';
    case Other = 'other';

    public function label(): string
    {
        return __('payment_methods.'.$this->value);
    }
}
