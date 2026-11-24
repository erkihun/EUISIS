<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\UseCases;

use Illuminate\Support\Facades\DB;
use Modules\Reporting\Application\Data\ActorContext;
use Modules\Reporting\Application\Ports\AuditRecorder;
use Modules\Reporting\Application\Ports\OrganizationDirectory;
use Modules\Reporting\Application\Support\ReportingAccessDenied;
use Modules\Reporting\Application\Support\ReportingGuard;
use Modules\Reporting\Application\Support\ReportingSettings;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Modules\Reporting\Domain\Workflow\WorkflowViolation;
use Modules\Reporting\Infrastructure\Persistence\Models\ReportType;
use Modules\Reporting\Infrastructure\Persistence\Models\Schedule;
use Modules\Reporting\Infrastructure\Persistence\Models\ScheduleTarget;

/**
 * Schedules and the institutions or people they bind. Targets are stored as
 * references; the actor must reach every organization they name.
 */
final readonly class ManageSchedules
{
    /** A schedule naming more institutions than this is split, so one publication stays a bounded transaction. */
    public const MAX_TARGETS = 2000;

    public function __construct(
        private OrganizationDirectory $organizations,
        private AuditRecorder $audit,
        private ReportingSettings $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated schedule fields
     * @param  list<array{organization_id: string, person_user_id?: ?int, preparer_user_id?: ?int, accountable_user_id?: ?int, include_descendants?: bool}>  $targets
     */
    public function save(ActorContext $actor, ?string $scheduleId, array $data, array $targets): Schedule
    {
        ReportingGuard::require($actor, ReportingRoles::PUBLISH_SCHEDULES);
        $this->settings->assertWritable();
        $type = ReportType::query()->findOrFail($data['report_type_id']);
        $rows = $this->resolveTargets($actor, $targets, $data['receiving_organization_id'] ?? $type->receiving_organization_id);

        return DB::transaction(function () use ($actor, $scheduleId, $data, $rows): Schedule {
            $schedule = $scheduleId === null ? new Schedule(['created_by' => $actor->userId]) : Schedule::query()->lockForUpdate()->findOrFail($scheduleId);
            $schedule->fill([...$data, 'updated_by' => $actor->userId])->save();

            // Targets are replaced as a set. Obligations already published keep their own snapshot.
            $keep = array_keys($rows);
            ScheduleTarget::query()->where('schedule_id', $schedule->id)->whereNotIn('subject_key', $keep)->delete();
            foreach ($rows as $subjectKey => $row) {
                ScheduleTarget::query()->updateOrCreate(['schedule_id' => $schedule->id, 'subject_key' => $subjectKey], $row);
            }
            $this->audit->record('schedule_saved', $actor, 'reporting.schedule', $schedule->id, null, ['targets' => count($rows), 'is_active' => $schedule->is_active]);

            return $schedule;
        });
    }

    /**
     * Checks scope and expands "this institution and everything under it".
     *
     * A work unit reports inside its own institution: a unit may be named only
     * when the institution that receives the report is the unit's institution.
     *
     * @param  list<array<string, mixed>>  $targets
     * @param  ?string  $receivingOrganizationId  the institution the reports go to
     * @return array<string, array<string, mixed>> subject key => target row
     */
    public function resolveTargets(ActorContext $actor, array $targets, ?string $receivingOrganizationId = null): array
    {
        $rows = [];
        $units = [];
        foreach ($targets as $target) {
            $organizationId = (string) $target['organization_id'];
            if (! $actor->reaches($organizationId)) {
                throw new ReportingAccessDenied(ReportingRoles::PUBLISH_SCHEDULES);
            }
            $unitId = isset($target['organization_unit_id']) ? (string) $target['organization_unit_id'] : null;
            if ($unitId !== null) {
                $units[$organizationId] ??= $this->organizations->units($organizationId);
                if (! isset($units[$organizationId][$unitId])) {
                    throw new WorkflowViolation('unknown_unit');
                }
                if ($receivingOrganizationId !== $organizationId) {
                    throw new WorkflowViolation('unit_outside_institution');
                }
                $rows["unit:{$unitId}"] ??= [
                    'organization_id' => $organizationId,
                    'organization_unit_id' => $unitId,
                    'person_user_id' => null,
                    'preparer_user_id' => $target['preparer_user_id'] ?? null,
                    'accountable_user_id' => $target['accountable_user_id'] ?? null,
                ];

                continue;
            }
            $personId = isset($target['person_user_id']) ? (int) $target['person_user_id'] : null;
            $ids = ($target['include_descendants'] ?? false) && $personId === null ? $this->organizations->subtreeIds($organizationId) : [$organizationId];
            foreach ($ids as $id) {
                // The subtree may run outside the actor's reach only for city-wide accounts; others keep what they reach.
                if (! $actor->reaches($id)) {
                    continue;
                }
                $key = $personId === null ? "org:{$id}" : "user:{$personId}";
                $rows[$key] ??= [
                    'organization_id' => $id,
                    'organization_unit_id' => null,
                    'person_user_id' => $personId,
                    'preparer_user_id' => $id === $organizationId ? ($target['preparer_user_id'] ?? null) : null,
                    'accountable_user_id' => $id === $organizationId ? ($target['accountable_user_id'] ?? null) : null,
                ];
            }
        }
        if ($rows === []) {
            throw new WorkflowViolation('no_targets');
        }
        if (count($rows) > self::MAX_TARGETS) {
            throw new WorkflowViolation('too_many_targets', ['max' => self::MAX_TARGETS]);
        }
        $known = $this->organizations->findMany(array_values(array_unique(array_column($rows, 'organization_id'))));
        foreach ($rows as $row) {
            if (! isset($known[$row['organization_id']])) {
                throw new WorkflowViolation('unknown_organization');
            }
        }

        return $rows;
    }
}
