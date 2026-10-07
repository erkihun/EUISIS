<?php

declare(strict_types=1);

namespace App\Services\DailyActivity;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\TaskStandardStatus;
use App\Models\PositionServiceTask;
use App\Models\PositionServiceTaskStandard;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Task standard versions (ስታንዳርድ መለኪያ / የBPR ዕቅድ).
 *
 * History is never rewritten:
 *  - a draft may be edited or deleted; an approved version never is;
 *  - approving a version closes the version in force before it on the day
 *    before it takes effect, so exactly one version measures any date;
 *  - retiring stops a version measuring new work, while items already
 *    measured keep the values they copied from it.
 */
class TaskStandardService
{
    /** Fields an administrator writes on a draft. */
    public const FIELDS = [
        'standard_measure', 'bpr_reference',
        'planned_quantity', 'quantity_unit',
        'planned_time_minutes',
        'planned_quality', 'quality_unit', 'quality_measure', 'quality_source',
        'effective_from', 'effective_to',
    ];

    public function __construct(
        private readonly WriteAuditLogAction $audit,
        private readonly DailyActivitySettings $settings,
    ) {}

    /** @param array<string, mixed> $data */
    public function createDraft(User $actor, PositionServiceTask $task, array $data): PositionServiceTaskStandard
    {
        return DB::transaction(function () use ($actor, $task, $data): PositionServiceTaskStandard {
            // Serialise version numbering per task.
            PositionServiceTask::query()->whereKey($task->getKey())->lockForUpdate()->first();
            $next = (int) PositionServiceTaskStandard::query()->where('task_id', $task->getKey())->max('version_no') + 1;

            $standard = new PositionServiceTaskStandard([
                ...$this->only($data),
                'task_id' => $task->getKey(),
                'organization_id' => $task->organization_id,
                'version_no' => $next,
                'status' => TaskStandardStatus::Draft,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
            $standard->save();

            $this->record(AuditEventType::WorkStandardSaved, $actor, $standard, null);

            return $standard;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(User $actor, PositionServiceTaskStandard $standard, array $data): PositionServiceTaskStandard
    {
        $this->assertDraft($standard);
        $before = $this->values($standard);
        $standard->fill([...$this->only($data), 'updated_by' => $actor->getKey()])->save();
        $this->record(AuditEventType::WorkStandardSaved, $actor, $standard, $before);

        return $standard;
    }

    public function deleteDraft(User $actor, PositionServiceTaskStandard $standard): void
    {
        $this->assertDraft($standard);
        $this->record(AuditEventType::WorkStandardSaved, $actor, $standard, $this->values($standard), deleted: true);
        $standard->delete();
    }

    public function approve(User $actor, PositionServiceTaskStandard $standard): PositionServiceTaskStandard
    {
        return DB::transaction(function () use ($actor, $standard): PositionServiceTaskStandard {
            PositionServiceTask::query()->whereKey($standard->task_id)->lockForUpdate()->first();
            $standard = PositionServiceTaskStandard::query()->whereKey($standard->getKey())->lockForUpdate()->firstOrFail();
            $this->assertDraft($standard);

            $from = $standard->effective_from->toDateString();
            $approved = PositionServiceTaskStandard::query()
                ->where('task_id', $standard->task_id)
                ->where('status', TaskStandardStatus::Approved->value)
                ->lockForUpdate()
                ->get();

            // A version may only follow the versions already approved: one
            // starting earlier than an approved version would rescore days
            // that version already measures.
            if ($approved->contains(fn (PositionServiceTaskStandard $other): bool => $other->effective_from->toDateString() >= $from)) {
                throw ValidationException::withMessages(['effective_from' => __('work-standards.starts_before_approved')]);
            }

            // Replacing a version in force cannot reach into the past: dates
            // already open for (backdated) entry would silently change
            // standard. A task's first version may start earlier.
            if ($approved->isNotEmpty() && $from < $this->settings->today()->toDateString()) {
                throw ValidationException::withMessages(['effective_from' => __('work-standards.starts_in_past')]);
            }

            // Close the version in force before this one.
            $closeOn = Carbon::parse($from)->subDay()->toDateString();
            foreach ($approved as $other) {
                if ($other->effective_to === null || $other->effective_to->toDateString() >= $from) {
                    $other->forceFill(['effective_to' => $closeOn, 'updated_by' => $actor->getKey()])->save();
                }
            }

            $standard->forceFill([
                'status' => TaskStandardStatus::Approved,
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->record(AuditEventType::WorkStandardApproved, $actor, $standard, null);

            return $standard;
        });
    }

    public function retire(User $actor, PositionServiceTaskStandard $standard): PositionServiceTaskStandard
    {
        if ($standard->status !== TaskStandardStatus::Approved) {
            throw ValidationException::withMessages(['status' => __('work-standards.only_approved_retire')]);
        }

        $standard->forceFill(['status' => TaskStandardStatus::Retired, 'updated_by' => $actor->getKey()])->save();
        $this->record(AuditEventType::WorkStandardRetired, $actor, $standard, null);

        return $standard;
    }

    private function assertDraft(PositionServiceTaskStandard $standard): void
    {
        if ($standard->status !== TaskStandardStatus::Draft) {
            throw ValidationException::withMessages(['status' => __('work-standards.approved_immutable')]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function only(array $data): array
    {
        return array_intersect_key($data, array_flip(self::FIELDS));
    }

    /** @return array<string, mixed> */
    private function values(PositionServiceTaskStandard $standard): array
    {
        return [
            ...$standard->only(self::FIELDS),
            'effective_from' => $standard->effective_from?->toDateString(),
            'effective_to' => $standard->effective_to?->toDateString(),
            'quality_source' => $standard->quality_source?->value,
            'status' => $standard->status?->value,
            'version_no' => $standard->version_no,
        ];
    }

    /** @param array<string, mixed>|null $before */
    private function record(AuditEventType $event, User $actor, PositionServiceTaskStandard $standard, ?array $before, bool $deleted = false): void
    {
        $this->audit->execute(
            $event,
            $actor,
            $standard,
            $standard->organization_id,
            $before,
            $deleted ? ['deleted' => true] : $this->values($standard),
            request: request(),
        );
    }
}
