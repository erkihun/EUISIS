<?php

declare(strict_types=1);

namespace App\Enums\Discipline;

/**
 * Case status. Transitions are made only by DisciplinaryCaseService and each
 * one is written to disciplinary_status_histories.
 */
enum DisciplinaryCaseStatus: string
{
    case Draft = 'draft';
    case Charged = 'charged';
    case UnderInvestigation = 'under_investigation';
    case ReportSubmitted = 'report_submitted';
    case Decided = 'decided';
    case DecisionDelivered = 'decision_delivered';
    case Closed = 'closed';
    // Art. 17(4): the institution withdraws the charge before a recommendation.
    case Discontinued = 'discontinued';
    // Art. 17(1): the employee's service ended (not by resignation, Art. 17(2)).
    case Terminated = 'terminated';

    public function isFinal(): bool
    {
        return in_array($this, [self::Closed, self::Discontinued, self::Terminated], true);
    }

    /** The committee or investigator is still gathering the record. */
    public function isInvestigating(): bool
    {
        return in_array($this, [self::Charged, self::UnderInvestigation], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
