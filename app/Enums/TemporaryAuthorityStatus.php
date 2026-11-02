<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of an acting appointment or a supervisor delegation (ADR 0025).
 * Expiry is not a stored state: a row is in force only between its dates, so
 * an ACTIVE row whose end date has passed grants nothing.
 */
enum TemporaryAuthorityStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';

    public function label(): string
    {
        return (string) __('reporting-lines.authority_statuses.'.$this->value);
    }
}
