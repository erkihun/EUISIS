<?php

declare(strict_types=1);

namespace App\Policies\Communication;

use App\Models\Communication\CommunicationReport;
use App\Models\User;

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
        return $user->can('communication.moderate');
    }

    public function review(User $user, CommunicationReport $report): bool
    {
        return $user->can('communication.moderate') && $report->reporter_id !== $user->getKey();
    }
}
