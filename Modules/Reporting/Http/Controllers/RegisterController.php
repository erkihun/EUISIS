<?php

declare(strict_types=1);

namespace Modules\Reporting\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Reporting\Application\Ports\AuditRecorder;
use Modules\Reporting\Application\Queries\ComplianceQuery;
use Modules\Reporting\Application\Queries\WorkQueues;
use Modules\Reporting\Application\Support\ReportingGuard;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Modules\Reporting\Domain\Compliance\ComplianceTally;
use Modules\Reporting\Http\Support\Filters;
use Modules\Reporting\Http\Support\RegisterExport;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The compliance register, its institution–period matrix, the archive of
 * decided reports, the calendar of deadlines and the export. All of them use
 * ComplianceQuery::base(), so a count on the screen, a row in the list and a
 * line in the exported file always follow the same scope and filters.
 */
final class RegisterController extends ReportingController
{
    public function index(Request $request, ComplianceQuery $compliance): Response
    {
        $actor = $this->actor($request);
        ReportingGuard::require($actor, ReportingRoles::VIEW);
        $filters = Filters::from($request);
        $view = $request->query('view') === 'matrix' && isset($filters['report_type_id']) ? 'matrix' : 'list';

        return Inertia::render('modules/reporting/Register', [
            'filters' => $filters,
            'view' => $view,
            'options' => Filters::options(),
            'rows' => $view === 'list' ? $compliance->register($actor, $filters) : null,
            'matrix' => $view === 'matrix' ? $compliance->matrix($actor, $filters) : null,
            'summary' => $compliance->summary($actor, $filters),
            'partitions' => ComplianceTally::partitions(),
            'can' => ['export' => $actor->can(ReportingRoles::EXPORT)],
            'archive' => false,
        ]);
    }

    /** Decided reports: the register limited to approved and rejected ones. */
    public function archive(Request $request, ComplianceQuery $compliance): Response
    {
        $actor = $this->actor($request);
        ReportingGuard::require($actor, ReportingRoles::VIEW);
        $filters = Filters::from($request, ['cycle_id', 'report_type_id', 'organization_id', 'search', 'due_from', 'due_to']);
        $filters['workflow'] = $request->query('workflow') === 'rejected' ? 'rejected' : 'approved';

        return Inertia::render('modules/reporting/Register', [
            'filters' => $filters, 'view' => 'list', 'options' => Filters::options(),
            'rows' => $compliance->register($actor, $filters), 'matrix' => null,
            'summary' => null, 'partitions' => ComplianceTally::partitions(),
            'can' => ['export' => $actor->can(ReportingRoles::EXPORT)], 'archive' => true,
        ]);
    }

    public function calendar(Request $request, WorkQueues $queues): Response
    {
        $actor = $this->actor($request);
        ReportingGuard::require($actor, ReportingRoles::VIEW);
        $month = is_string($request->query('month')) && preg_match('/^\d{4}-\d{2}$/', $request->query('month')) === 1 ? $request->query('month') : now('Africa/Addis_Ababa')->format('Y-m');
        $from = $month.'-01';
        $to = date('Y-m-t', (int) strtotime($from));

        return Inertia::render('modules/reporting/Calendar', [
            'month' => $month,
            'entries' => $queues->calendar($actor, $from, $to),
        ]);
    }

    /** CSV or Excel, with the page's filters. Needs its own permission; the export is audited with its filters and row count. */
    public function export(Request $request, ComplianceQuery $compliance, RegisterExport $export, AuditRecorder $audit): HttpResponse
    {
        $actor = $this->actor($request);
        ReportingGuard::require($actor, ReportingRoles::VIEW);
        ReportingGuard::require($actor, ReportingRoles::EXPORT);
        $filters = Filters::from($request);
        $format = $request->query('format') === 'xlsx' ? 'xlsx' : 'csv';

        return $export->download($compliance, $actor, $filters, $format, $this->language(),
            fn (int $rows) => $audit->record('exported', $actor, 'reporting.register', 'register', null, ['format' => $format, 'filters' => $filters, 'rows' => $rows]));
    }
}
