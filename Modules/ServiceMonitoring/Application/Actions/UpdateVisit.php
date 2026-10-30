<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Domain\Enums\VisitStatus;
use Modules\ServiceMonitoring\Domain\Models\MonitoringVisit;

/** Updates a visit's date, purpose, summary, status and team. */
final readonly class UpdateVisit
{
    public function __construct(private ServiceMonitoringAudit $audit) {}

    /** @param array{visited_on: string, purpose: string, summary?: ?string, status: string, member_ids?: list<int>} $data */
    public function execute(MonitoringVisit $visit, array $data, User $actor): MonitoringVisit
    {
        return DB::transaction(function () use ($visit, $data, $actor): MonitoringVisit {
            $visit->fill([
                'visited_on' => $data['visited_on'],
                'purpose' => $data['purpose'],
                'summary' => $data['summary'] ?? null,
                'status' => VisitStatus::from($data['status']),
            ])->save();
            if (array_key_exists('member_ids', $data)) {
                $visit->members()->sync(array_values(array_unique($data['member_ids'])));
            }
            $this->audit->record(AuditEventType::ServiceMonitoringVisitSaved, $actor, $visit, $visit->participant?->organization_id, ['status' => $data['status'], 'visited_on' => $data['visited_on']]);

            return $visit;
        });
    }
}
