<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Queries;

use Modules\Reporting\Application\Data\ActorContext;
use Modules\Reporting\Contracts\Data\ComplianceSummary;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Modules\Reporting\Contracts\ReportingCompliance;

/** The public contract, answered by the same query the module's own screens use. */
final readonly class ReportingComplianceService implements ReportingCompliance
{
    public function __construct(private ComplianceQuery $compliance) {}

    public function summary(?array $organizationIds, ?string $asOf = null): ComplianceSummary
    {
        // The caller has already authorized the organization set; inside, it is a read-only
        // context, and the set limits the obligations to those the organizations OWE.
        $reader = new ActorContext(0, 'contract', true, [], [ReportingRoles::VIEW]);
        $summary = $this->compliance->summary($reader, array_filter(['as_of' => $asOf, 'organization_ids' => $organizationIds], fn (mixed $v): bool => $v !== null));

        return new ComplianceSummary($summary['as_of'], $summary['counts'], $summary['rates']['submission_rate'], $summary['rates']['on_time_rate'], $summary['rates']['approval_rate']);
    }
}
