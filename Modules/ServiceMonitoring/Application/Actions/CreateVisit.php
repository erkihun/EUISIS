<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Domain\Enums\VisitStatus;
use Modules\ServiceMonitoring\Domain\Models\MonitoringVisit;
use Modules\ServiceMonitoring\Domain\Models\Participant;

/** Plans a monitoring visit with its team (D22). */
final readonly class CreateVisit
{
    public function __construct(private ServiceMonitoringAudit $audit) {}

    /** @param array{period_id?: ?string, visited_on: string, purpose: string, summary?: ?string, member_ids?: list<int>} $data */
    public function execute(Participant $participant, array $data, User $actor): MonitoringVisit
    {
        return DB::transaction(function () use ($participant, $data, $actor): MonitoringVisit {
            $visit = MonitoringVisit::query()->create([
                'participant_id' => $participant->id,
                'period_id' => $data['period_id'] ?? null,
                'visited_on' => $data['visited_on'],
                'purpose' => $data['purpose'],
                'summary' => $data['summary'] ?? null,
                'status' => VisitStatus::Planned,
                'created_by' => $actor->id,
            ]);
            $visit->syncMembers([$actor->id, ...($data['member_ids'] ?? [])]);
            $this->audit->record(AuditEventType::ServiceMonitoringVisitSaved, $actor, $visit, $participant->organization_id, ['visited_on' => $data['visited_on'], 'members' => $visit->members()->pluck('users.id')->all()]);

            return $visit;
        });
    }
}
