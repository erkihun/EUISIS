<?php

declare(strict_types=1);

namespace Modules\Recruitment\Contracts\Data;

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
        return (string) __('enums.vacancy_application_status.'.$this->value);
    }
}
