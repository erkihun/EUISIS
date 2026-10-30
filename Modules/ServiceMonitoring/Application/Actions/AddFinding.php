<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Domain\Enums\FindingSeverity;
use Modules\ServiceMonitoring\Domain\Models\MonitoringVisit;
use Modules\ServiceMonitoring\Domain\Models\VisitFinding;

/** Records a finding of a visit, optionally against a form section. */
final readonly class AddFinding
{
    public function __construct(private ServiceMonitoringAudit $audit) {}

    public function execute(MonitoringVisit $visit, ?int $section, string $body, FindingSeverity $severity, User $actor): VisitFinding
    {
        $finding = VisitFinding::query()->create(['visit_id' => $visit->id, 'section' => $section, 'body' => $body, 'severity' => $severity, 'created_by' => $actor->id]);
        $this->audit->record(AuditEventType::ServiceMonitoringFindingAdded, $actor, $finding, $visit->participant?->organization_id, ['visit_id' => $visit->id, 'section' => $section, 'severity' => $severity->value]);

        return $finding;
    }
}
