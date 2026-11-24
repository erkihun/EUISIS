<?php

declare(strict_types=1);

namespace Modules\Reporting\Contracts;

use Modules\Reporting\Contracts\Data\ComplianceSummary;

/**
 * The module's public query: how well a set of organizations meets its
 * reporting obligations. The caller is responsible for passing only the
 * organizations its own viewer is authorized for.
 */
interface ReportingCompliance
{
    /**
     * @param  list<string>|null  $organizationIds  null: every organization
     * @param  string|null  $asOf  ISO 8601 moment; null: now
     */
    public function summary(?array $organizationIds, ?string $asOf = null): ComplianceSummary;
}
