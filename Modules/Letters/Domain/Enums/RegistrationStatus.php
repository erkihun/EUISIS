<?php

declare(strict_types=1);

namespace Modules\Letters\Domain\Enums;

/**
 * One institution's handling of a letter. A letter dispatched to another
 * institution in the system waits as `pending_receipt` until that
 * institution's registrar receives it and its own number is drawn.
 */
enum RegistrationStatus: string
{
    case PendingReceipt = 'pending_receipt';
    case Registered = 'registered';
    case Routed = 'routed';
    case InProgress = 'in_progress';
    case Closed = 'closed';

    public function isOpen(): bool
    {
        return $this !== self::Closed && $this !== self::PendingReceipt;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
