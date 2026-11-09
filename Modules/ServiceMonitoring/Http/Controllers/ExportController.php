<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Http\Controllers;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAccess;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Domain\Enums\ExportStatus;
use Modules\ServiceMonitoring\Domain\Enums\ReportRange;
use Modules\ServiceMonitoring\Domain\Models\MonitoringExport;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Domain\Models\Submission;
use Modules\ServiceMonitoring\Http\Requests\RequestExportRequest;
use Modules\ServiceMonitoring\Infrastructure\Jobs\GenerateServiceMonitoringExport;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Official-form exports (D25): queued, stored on the private disk, downloaded
 * as an attachment by the requester only. The requester must be able to see
 * the scope; names need accountability.export_named. Every request and
 * download is audited.
 */
final class ExportController extends Controller
{
    public function __construct(private readonly ServiceMonitoringAccess $access, private readonly ServiceMonitoringAudit $audit) {}

    public function store(RequestExportRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $scopeId = $data['scope_id'] ?? null;
        $allowed = match ($data['scope_type']) {
            MonitoringExport::SCOPE_SUBMISSION => ($submission = Submission::query()->find($scopeId)) !== null && $user->can('export', $submission),
            MonitoringExport::SCOPE_SUB_CITY => ($participant = Participant::query()->find($scopeId)) !== null && $this->access->canConsolidateSubCity($user, $participant),
            default => $this->access->isCitywide($user),
        };
        abort_unless($allowed, 403);

        $export = MonitoringExport::query()->create([
            'requested_by' => $user->id,
            'scope_type' => $data['scope_type'],
            'scope_id' => $data['scope_type'] === MonitoringExport::SCOPE_CITY ? null : $scopeId,
            'fiscal_year_id' => $data['fiscal_year_id'],
            'quarter' => (int) $data['quarter'],
            'range' => ReportRange::from($data['range']),
            'format' => $data['format'],
            'locale' => app()->getLocale(),
            'named' => (bool) ($data['named'] ?? false) && $this->access->canExportNamed($user),
            'status' => ExportStatus::Queued,
        ]);
        $this->audit->record(AuditEventType::ServiceMonitoringExportRequested, $user, $export, null,
            ['scope_type' => $export->scope_type, 'scope_id' => $export->scope_id, 'range' => $export->range->value, 'format' => $export->format, 'named' => $export->named]);
        GenerateServiceMonitoringExport::dispatch($export);

        return back()->with('success', __('service-monitoring.flash.export_queued'));
    }

    public function download(Request $request, MonitoringExport $export): StreamedResponse
    {
        $this->authorize('download', $export);
        $this->audit->record(AuditEventType::ServiceMonitoringExportDownloaded, $request->user(), $export, null, ['scope_type' => $export->scope_type, 'named' => $export->named]);

        return Storage::disk($export->disk ?? 'local')->download((string) $export->path, (string) $export->filename);
    }
}
