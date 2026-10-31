<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Policies;

use App\Models\User;
use Modules\Communication\Domain\Models\CommunicationReport;

/**
 * Moderators review reports. Opening a report shows only the reported message
 * and its immediate context (ReportedContentViewer), never the whole private
 * conversation, and each opening is audited.
 */
class CommunicationReportPolicy
{
    public function create(User $user): bool
    {
        return $user->can('communication.reports.create');
    }

    public function viewAny(User $user): bool
    {
        return $user->checkPermissionTo('communication.moderate');
    }

    public function review(User $user, CommunicationReport $report): bool
    {
        return $user->checkPermissionTo('communication.moderate') && $report->reporter_id !== $user->getKey();
    }
}
