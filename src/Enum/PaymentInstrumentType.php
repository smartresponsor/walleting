<?php

declare(strict_types=1);

namespace App\Walleting\Enum;

enum PaymentInstrumentType: string
{
    case Card = 'card';
    case BankAccount = 'bank_account';
    case ExternalWallet = 'external_wallet';
    case Cash = 'cash';
}
