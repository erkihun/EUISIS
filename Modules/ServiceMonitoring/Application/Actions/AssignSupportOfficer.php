<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Domain\Models\SupportAssignment;

/** Sets the support officers of a participant (many-to-many, D22). Only users holding support.respond qualify. */
final readonly class AssignSupportOfficer
{
    public function __construct(private ServiceMonitoringAudit $audit) {}

    /** @param list<int> $userIds */
    public function execute(Participant $participant, array $userIds, User $actor): void
    {
        $eligible = User::query()->whereKey($userIds)->get()->filter(fn (User $u): bool => $u->can('service_monitoring.support.respond'))->pluck('id')->all();

        DB::transaction(function () use ($participant, $eligible, $actor): void {
            $current = SupportAssignment::query()->where('participant_id', $participant->id)->pluck('user_id')->all();
            SupportAssignment::query()->where('participant_id', $participant->id)->whereNotIn('user_id', $eligible)->delete();
            foreach (array_diff($eligible, $current) as $userId) {
                SupportAssignment::query()->create(['participant_id' => $participant->id, 'user_id' => $userId, 'assigned_by' => $actor->id]);
            }

            $this->audit->record(AuditEventType::ServiceMonitoringSupportAssigned, $actor, $participant, $participant->organization_id,
                ['user_ids' => array_values($eligible)], old: ['user_ids' => array_values($current)]);
        });
    }
}
