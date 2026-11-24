<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain\Compliance;

use DateTimeImmutable;
use Modules\Reporting\Domain\Enums\Applicability;
use Modules\Reporting\Domain\Enums\Timeliness;
use Modules\Reporting\Domain\Enums\WorkflowStatus;

/**
 * Counts obligations for an as-of time. Every dashboard figure and every
 * register row passes through this one class (or TimelinessRule directly),
 * which is why the two reconcile.
 *
 * Each obligation is counted once, whatever its number of versions. The
 * categories overlap on purpose (an obligation is "submitted late" AND "under
 * review"); only the partitions named in partitions() add up to a total.
 */
final class ComplianceTally
{
    /** @var array<string, int> */
    private array $counts = [
        'expected' => 0, 'exempted' => 0, 'cancelled' => 0, 'applicable' => 0,
        'not_yet_due' => 0, 'due_required' => 0,
        'submitted_on_time' => 0, 'submitted_late' => 0, 'overdue_unsubmitted' => 0,
        'early_submissions' => 0, 'on_time_by_original_deadline' => 0,
        'not_started' => 0, 'draft' => 0, 'submitted' => 0, 'under_review' => 0, 'returned' => 0, 'approved' => 0, 'rejected' => 0,
        'ever_submitted' => 0, 'overdue_reviews' => 0,
    ];

    public function __construct(private readonly DateTimeImmutable $asOf) {}

    public function add(ObligationFacts $facts): void
    {
        $this->counts['expected']++;
        if ($facts->applicability !== Applicability::Required) {
            $this->counts[$facts->applicability->value]++;

            return;
        }
        $this->counts['applicable']++;
        $this->counts[$facts->workflow->value]++;

        $submitted = $facts->firstSubmittedAt !== null && $facts->firstSubmittedAt <= $this->asOf;
        if ($submitted) {
            $this->counts['ever_submitted']++;
        }
        if ($facts->workflow->awaitsDecision() && $facts->reviewDueAt !== null && $facts->reviewDueAt < $this->asOf) {
            $this->counts['overdue_reviews']++;
        }

        match (TimelinessRule::of($facts->firstSubmittedAt, $facts->effectiveDueAt, $this->asOf)) {
            Timeliness::NotDue => $this->counts['not_yet_due']++,
            Timeliness::Early => [$this->counts['not_yet_due']++, $this->counts['early_submissions']++],
            Timeliness::OnTime => [$this->counts['due_required']++, $this->counts['submitted_on_time']++],
            Timeliness::Late => [$this->counts['due_required']++, $this->counts['submitted_late']++],
            Timeliness::Overdue => [$this->counts['due_required']++, $this->counts['overdue_unsubmitted']++],
        };

        // Compliance against the ORIGINAL deadline survives any extension.
        if ($submitted && $facts->originalDueAt <= $this->asOf && $facts->firstSubmittedAt <= $facts->originalDueAt) {
            $this->counts['on_time_by_original_deadline']++;
        }
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return $this->counts;
    }

    /**
     * Rates as fractions between 0 and 1; null when the denominator is zero
     * (shown as N/A, never as 0 % or 100 %).
     *
     * @return array{submission_rate: ?float, on_time_rate: ?float, approval_rate: ?float}
     */
    public function rates(): array
    {
        $c = $this->counts;

        return [
            'submission_rate' => self::ratio($c['submitted_on_time'] + $c['submitted_late'], $c['due_required']),
            'on_time_rate' => self::ratio($c['submitted_on_time'], $c['due_required']),
            'approval_rate' => self::ratio($c['approved'], $c['ever_submitted']),
        ];
    }

    /**
     * The groups of categories that add up exactly, so a screen can say which
     * figures may be summed and which overlap.
     *
     * @return array<string, list<string>> total => its parts
     */
    public static function partitions(): array
    {
        return [
            'expected' => ['applicable', 'exempted', 'cancelled'],
            'applicable' => ['not_yet_due', 'due_required'],
            'due_required' => ['submitted_on_time', 'submitted_late', 'overdue_unsubmitted'],
            'applicable_by_workflow' => [WorkflowStatus::NotStarted->value, WorkflowStatus::Draft->value, WorkflowStatus::Submitted->value, WorkflowStatus::UnderReview->value, WorkflowStatus::Returned->value, WorkflowStatus::Approved->value, WorkflowStatus::Rejected->value],
        ];
    }

    private static function ratio(int $numerator, int $denominator): ?float
    {
        return $denominator === 0 ? null : $numerator / $denominator;
    }
}
