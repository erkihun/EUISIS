<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Domain\Enums\ReportingLevel;
use Modules\ServiceMonitoring\Domain\Models\Participant;

/**
 * Changes a registry row: level, parent sub-city or active dates. Submissions
 * keep their own level/form snapshot, so a change applies from the next
 * submission on.
 */
final readonly class UpdateParticipant
{
    public function __construct(private ServiceMonitoringAudit $audit) {}

    /** @param array{level: string, parent_participant_id?: ?string, active_from: string, active_to?: ?string} $data */
    public function execute(Participant $participant, array $data, User $actor): Participant
    {
        $level = ReportingLevel::from($data['level']);
        $parentId = $data['parent_participant_id'] ?? null;
        RegisterParticipant::assertParent($level, $parentId === $participant->id ? null : $parentId);

        return DB::transaction(function () use ($participant, $data, $level, $parentId, $actor): Participant {
            $old = $participant->only(['level', 'parent_participant_id', 'active_from', 'active_to']);
            $participant->fill([
                'level' => $level,
                'parent_participant_id' => $level === ReportingLevel::Woreda ? $parentId : null,
                'active_from' => $data['active_from'],
                'active_to' => $data['active_to'] ?? null,
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->record(AuditEventType::ServiceMonitoringParticipantUpdated, $actor, $participant, $participant->organization_id,
                $participant->only(['level', 'parent_participant_id', 'active_from', 'active_to']), old: array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : (string) $v, $old));

            return $participant;
        });
    }
}
