<?php

declare(strict_types=1);

namespace App\Enums;

enum CodeRuleResetFrequency: string
{
    case Never = 'never';
    case Yearly = 'yearly';
    case Monthly = 'monthly';
    case Daily = 'daily';
}
