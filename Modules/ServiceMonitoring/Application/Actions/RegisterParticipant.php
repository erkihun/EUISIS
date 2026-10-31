<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Domain\Enums\ReportingLevel;
use Modules\ServiceMonitoring\Domain\Models\Participant;

/**
 * Adds an organization to the reporting registry (D7). One active row per
 * organization (checked here and by a unique index); a woreda reports
 * through an active sub-city participant, other levels have no parent.
 */
final readonly class RegisterParticipant
{
    public function __construct(private ServiceMonitoringAudit $audit) {}

    /** @param array{organization_id: string, level: string, parent_participant_id?: ?string, active_from: string, active_to?: ?string} $data */
    public function execute(array $data, ?User $actor): Participant
    {
        $level = ReportingLevel::from($data['level']);
        $parentId = $data['parent_participant_id'] ?? null;
        self::assertParent($level, $parentId);

        return DB::transaction(function () use ($data, $level, $parentId, $actor): Participant {
            $taken = Participant::query()->where('organization_id', $data['organization_id'])->whereNull('active_to')->lockForUpdate()->exists();
            if ($taken) {
                throw ValidationException::withMessages(['organization_id' => __('service-monitoring.validation.participant_active')]);
            }

            $participant = Participant::query()->create([
                'organization_id' => $data['organization_id'],
                'level' => $level,
                'parent_participant_id' => $level === ReportingLevel::Woreda ? $parentId : null,
                'active_from' => $data['active_from'],
                'active_to' => $data['active_to'] ?? null,
                'created_by' => $actor?->id,
            ]);

            $this->audit->record(AuditEventType::ServiceMonitoringParticipantRegistered, $actor, $participant, $participant->organization_id,
                ['level' => $level->value, 'parent_participant_id' => $participant->parent_participant_id, 'active_from' => $data['active_from']]);

            return $participant;
        });
    }

    public static function assertParent(ReportingLevel $level, ?string $parentId): void
    {
        $valid = $level === ReportingLevel::Woreda
            ? $parentId !== null && Participant::query()->whereKey($parentId)->where('level', ReportingLevel::SubCity->value)->whereNull('active_to')->exists()
            : $parentId === null;
        if (! $valid) {
            throw ValidationException::withMessages(['parent_participant_id' => __('service-monitoring.validation.parent_level')]);
        }
    }
}
