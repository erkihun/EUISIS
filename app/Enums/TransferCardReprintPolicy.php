<?php

declare(strict_types=1);

namespace App\Enums;

enum TransferCardReprintPolicy: string
{
    case NoReprint = 'no_reprint';
    case RequestReprint = 'request_reprint';
    case AutoReprint = 'auto_reprint';

    public function label(): string
    {
        return match ($this) {
            self::NoReprint => 'No Reprint',
            self::RequestReprint => 'Create Reprint Request',
            self::AutoReprint => 'Auto Reprint',
        };
    }
}
