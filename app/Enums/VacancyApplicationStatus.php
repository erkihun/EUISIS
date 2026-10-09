<?php

declare(strict_types=1);

namespace App\Enums;

enum VacancyApplicationStatus: string
{
    case Submitted = 'submitted';
    case Withdrawn = 'withdrawn';
    case Screened = 'screened';
    case Shortlisted = 'shortlisted';
    case Selected = 'selected';
    case Rejected = 'rejected';
    case Transferred = 'transferred';

    public function isFinal(): bool
    {
        return in_array($this, [self::Withdrawn, self::Rejected, self::Transferred], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::Withdrawn => 'Withdrawn',
            self::Screened => 'Screened',
            self::Shortlisted => 'Shortlisted',
            self::Selected => 'Selected',
            self::Rejected => 'Rejected',
            self::Transferred => 'Transferred',
        };
    }
}
