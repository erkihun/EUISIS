<?php

declare(strict_types=1);

namespace Modules\Transfer\Domain\Enums;

enum TransferCardReprintPolicy: string
{
    case NoReprint = 'no_reprint';
    case RequestReprint = 'request_reprint';
    case AutoReprint = 'auto_reprint';

    public function label(): string
    {
        return (string) __('enums.transfer_card_reprint_policy.'.$this->value);
    }
}
