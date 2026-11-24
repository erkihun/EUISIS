<?php

declare(strict_types=1);

namespace Modules\Reporting\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Reporting\Application\Queries\ComplianceQuery;
use Modules\Reporting\Application\Queries\WorkQueues;
use Modules\Reporting\Application\Support\ReportingGuard;
use Modules\Reporting\Application\Support\ReportingSettings;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Modules\Reporting\Domain\Compliance\ComplianceTally;
use Modules\Reporting\Http\Support\Filters;
use Modules\Reporting\Http\Support\Present;
use Modules\Reporting\Infrastructure\Persistence\Models\Obligation;

/**
 * The overview: one page for every role. What it shows follows the actor's
 * scope and permissions: central oversight sees the city, an institution's
 * manager its own institution, a preparer their assignments, a reviewer the
 * inbox. The figures come from the same query as the register.
 */
final class OverviewController extends ReportingController
{
    public function __invoke(Request $request, ComplianceQuery $compliance, WorkQueues $queues, ReportingSettings $settings): Response
    {
        $actor = $this->actor($request);
        ReportingGuard::require($actor, ReportingRoles::VIEW);
        $filters = Filters::from($request, ['report_type_id', 'cycle_id', 'as_of']);

        return Inertia::render('modules/reporting/Overview', [
            'filters' => $filters,
            'options' => Filters::options(),
            'summary' => $compliance->summary($actor, $filters),
            'partitions' => ComplianceTally::partitions(),
            'trend' => $compliance->trend($actor, $filters),
            'role' => [
                'oversight' => $actor->unrestricted,
                'prepares' => $actor->can(ReportingRoles::PREPARE) || $actor->can(ReportingRoles::SUBMIT),
                'reviews' => $actor->can(ReportingRoles::REVIEW) || $actor->can(ReportingRoles::APPROVE),
            ],
            'queues' => [
                'assignments_open' => (clone $queues->assignments($actor))->count(),
                'review_inbox' => (clone $queues->reviewInbox($actor))->count(),
                'review_mine' => (clone $queues->reviewInbox($actor, true))->count(),
                'extensions_pending' => $queues->pendingExtensions($actor)->count(),
            ],
            'next_due' => $queues->assignments($actor)->limit(5)->get()->map(fn (Obligation $o): array => Present::obligation($o))->all(),
            'paused' => $settings->isPaused(),
        ]);
    }
}
