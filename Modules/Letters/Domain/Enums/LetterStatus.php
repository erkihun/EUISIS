<?php

declare(strict_types=1);

namespace Modules\Letters\Domain\Enums;

/**
 * The document's own lifecycle. Composed letters (outgoing, internal) go
 * draft → pending_approval → approved → dispatched; a letter that arrived from
 * outside the system is `received` from the moment it is registered. Handling
 * inside each institution is tracked on the registration, not here.
 */
enum LetterStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Dispatched = 'dispatched';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Dispatched, self::Received, self::Cancelled], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
