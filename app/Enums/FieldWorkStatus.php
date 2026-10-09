<?php

declare(strict_types=1);

namespace App\Enums;

/** Lifecycle of an official field-work authorization, not an assignment. */
enum FieldWorkStatus: string
{
    case Draft = 'draft';
    case PendingSupervisorApproval = 'pending_supervisor_approval';
    case ReturnedForCorrection = 'returned_for_correction';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::ReturnedForCorrection], true);
    }

    public function isOpen(): bool
    {
        return $this === self::Approved;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
