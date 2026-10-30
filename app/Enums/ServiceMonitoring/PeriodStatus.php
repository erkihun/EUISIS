<?php

declare(strict_types=1);

namespace App\Enums\ServiceMonitoring;

enum PeriodStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
