<?php

declare(strict_types=1);

namespace Modules\Transfer\Contracts\Data;

enum TransferAnnouncementStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return in_array($this, [self::Closed, self::Cancelled], true);
    }

    public function canAcceptApplications(): bool
    {
        return $this === self::Published;
    }

    public function label(): string
    {
        return (string) __('enums.transfer_announcement_status.'.$this->value);
    }
}
