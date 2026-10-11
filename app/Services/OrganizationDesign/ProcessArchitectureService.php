<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign;

use App\Enums\AuditEventType;
use App\Enums\OrganizationDesignStatus;
use App\Models\PositionServiceTask;
use App\Models\PositionServiceTaskStandard;
use App\Models\Process;
use App\Models\ProcessActivity;
use App\Models\ProcessTask;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkItemIo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The process architecture (brief §17–25): a service's process as a tree
 * of activities and tasks with typed I/O rows. The process is the versioned
 * aggregate: while it is a draft its tree changes; once published nothing
 * under it changes, and a new version deep-copies the tree. Position tasks
 * (Daily Work) may be traced to master tasks; they keep their own
 * standards.
 */
class ProcessArchitectureService
{
    public const FIELDS = ['service_id', 'code', 'name_en', 'name_am', 'description_en', 'description_am', 'process_type', 'owner_organization_unit_id', 'effective_from', 'effective_to'];

    public const ACTIVITY_FIELDS = ['code', 'name_en', 'name_am', 'description_en', 'description_am', 'sequence', 'is_active'];

    public const TASK_FIELDS = ['code', 'name_en', 'name_am', 'description_en', 'description_am', 'sequence', 'required_output', 'is_active'];

    public function __construct(private readonly OrganizationDesignAudit $audit) {}

    // ── Process versions ─────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    public function createDraft(User $actor, string $organizationId, array $data): Process
    {
        $this->assertOwnService($organizationId, $data['service_id'] ?? null);

        return DB::transaction(function () use ($actor, $organizationId, $data): Process {
            $process = new Process([
                ...array_intersect_key($data, array_flip(self::FIELDS)),
                'organization_id' => $organizationId,
                'lineage_key' => (string) Str::uuid(),
                'version_no' => 1,
                'status' => OrganizationDesignStatus::Draft,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
            $process->save();
            $this->audit->record(AuditEventType::ProcessCreated, $actor, $process, $process->only(self::FIELDS));
            $this->audit->transition($actor, $process, null, OrganizationDesignStatus::Draft->value);

            return $process;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(User $actor, Process $process, array $data): Process
    {
        $this->assertEditable($process);
        if (array_key_exists('service_id', $data)) {
            $this->assertOwnService($process->organization_id, $data['service_id']);
        }
        $old = $process->only(self::FIELDS);
        $process->fill([...array_intersect_key($data, array_flip(self::FIELDS)), 'updated_by' => $actor->getKey()])->save();
        $this->audit->record(AuditEventType::ProcessUpdated, $actor, $process, $process->only(self::FIELDS), $old);

        return $process;
    }

    /**
     * A new draft version from the published one, with its whole tree
     * (activities, tasks, I/O rows) copied so the published version stays
     * exactly as approved.
     *
     * @param  array<string, mixed>  $changes
     */
    public function newVersion(User $actor, Process $published, array $changes): Process
    {
        if ($published->status !== OrganizationDesignStatus::Published) {
            throw ValidationException::withMessages(['status' => __('organization-design.process_version_from_published_only')]);
        }

        return DB::transaction(function () use ($actor, $published, $changes): Process {
            Process::query()->whereKey($published->getKey())->lockForUpdate()->first();
            if (Process::query()->where('lineage_key', $published->lineage_key)->where('status', OrganizationDesignStatus::Draft)->exists()) {
                throw ValidationException::withMessages(['status' => __('organization-design.process_draft_exists')]);
            }
            $next = (int) Process::query()->where('lineage_key', $published->lineage_key)->max('version_no') + 1;

            $draft = new Process([
                ...$published->only(self::FIELDS),
                ...array_intersect_key($changes, array_flip(self::FIELDS)),
                'organization_id' => $published->organization_id,
                'lineage_key' => $published->lineage_key,
                'version_no' => $next,
                'status' => OrganizationDesignStatus::Draft,
                'effective_to' => null,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
            $draft->save();
            $this->copyTree($actor, $published, $draft);

            $this->audit->record(AuditEventType::ProcessCreated, $actor, $draft, ['version_no' => $next, 'supersedes' => $published->getKey()]);
            $this->audit->transition($actor, $draft, null, OrganizationDesignStatus::Draft->value);

            return $draft;
        });
    }

    public function publish(User $actor, Process $process): Process
    {
        $this->assertEditable($process);
        if ($process->created_by !== null && (int) $process->created_by === (int) $actor->getKey()) {
            throw ValidationException::withMessages(['status' => __('organization-design.process_publish_same_person')]);
        }

        return DB::transaction(function () use ($actor, $process): Process {
            $inForce = Process::query()->where('lineage_key', $process->lineage_key)->where('status', OrganizationDesignStatus::Published)->lockForUpdate()->first();
            $from = Carbon::parse($process->effective_from);
            if ($inForce !== null && $from->lte(Carbon::parse($inForce->effective_from))) {
                throw ValidationException::withMessages(['effective_from' => __('organization-design.process_version_must_start_later')]);
            }
            if ($inForce !== null) {
                $inForce->forceFill(['status' => OrganizationDesignStatus::Superseded, 'effective_to' => $from->copy()->subDay()->toDateString(), 'updated_by' => $actor->getKey()])->save();
                $this->audit->record(AuditEventType::ProcessSuperseded, $actor, $inForce, ['superseded_by' => $process->getKey()]);
                $this->audit->transition($actor, $inForce, OrganizationDesignStatus::Published->value, OrganizationDesignStatus::Superseded->value);
            }
            $process->forceFill(['status' => OrganizationDesignStatus::Published, 'approved_by' => $actor->getKey(), 'approved_at' => now(), 'updated_by' => $actor->getKey()])->save();
            $this->audit->record(AuditEventType::ProcessPublished, $actor, $process, ['version_no' => $process->version_no]);
            $this->audit->transition($actor, $process, OrganizationDesignStatus::Draft->value, OrganizationDesignStatus::Published->value);

            return $process;
        });
    }

    public function cancel(User $actor, Process $process, ?string $reason = null): Process
    {
        $this->assertEditable($process);
        $process->forceFill(['status' => OrganizationDesignStatus::Cancelled, 'updated_by' => $actor->getKey()])->save();
        $this->audit->record(AuditEventType::ProcessCancelled, $actor, $process, [], null, $reason);
        $this->audit->transition($actor, $process, OrganizationDesignStatus::Draft->value, OrganizationDesignStatus::Cancelled->value, $reason);

        return $process;
    }

    // ── Activities ───────────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    public function createActivity(User $actor, Process $process, array $data): ProcessActivity
    {
        $this->assertEditable($process);

        return DB::transaction(function () use ($actor, $process, $data): ProcessActivity {
            $parent = $this->parentFor($process, $data['parent_activity_id'] ?? null);
            $activity = new ProcessActivity([
                ...array_intersect_key($data, array_flip(self::ACTIVITY_FIELDS)),
                'process_id' => $process->getKey(),
                'organization_id' => $process->organization_id,
                'parent_activity_id' => $parent?->getKey(),
                'depth' => $parent ? $parent->depth + 1 : 0,
                'path' => '',
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
            $activity->save();
            $activity->forceFill(['path' => $parent ? $parent->path.'/'.$activity->getKey() : (string) $activity->getKey()])->save();
            $this->audit->record(AuditEventType::ActivitySaved, $actor, $activity, $activity->only(self::ACTIVITY_FIELDS));

            return $activity;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateActivity(User $actor, ProcessActivity $activity, array $data): ProcessActivity
    {
        $process = $activity->process;
        $this->assertEditable($process);

        return DB::transaction(function () use ($actor, $process, $activity, $data): ProcessActivity {
            $old = $activity->only([...self::ACTIVITY_FIELDS, 'parent_activity_id']);
            $activity->fill([...array_intersect_key($data, array_flip(self::ACTIVITY_FIELDS)), 'updated_by' => $actor->getKey()]);

            if (array_key_exists('parent_activity_id', $data) && $data['parent_activity_id'] !== $activity->parent_activity_id) {
                $parent = $this->parentFor($process, $data['parent_activity_id']);
                if ($parent !== null && ($parent->getKey() === $activity->getKey() || $activity->isAncestorOf($parent))) {
                    throw ValidationException::withMessages(['parent_activity_id' => __('organization-design.activity_cycle')]);
                }
                $this->moveSubtree($activity, $parent);
                $this->audit->record(AuditEventType::ActivityMoved, $actor, $activity, ['parent_activity_id' => $parent?->getKey()], ['parent_activity_id' => $old['parent_activity_id']]);
            }
            $activity->save();
            $this->audit->record(AuditEventType::ActivitySaved, $actor, $activity, $activity->only(self::ACTIVITY_FIELDS), $old);

            return $activity;
        });
    }

    public function removeActivity(User $actor, ProcessActivity $activity): void
    {
        $this->assertEditable($activity->process);
        if ($activity->children()->exists() || $activity->tasks()->exists()) {
            throw ValidationException::withMessages(['activity' => __('organization-design.activity_has_children')]);
        }
        if ($activity->standards()->exists()) {
            throw ValidationException::withMessages(['activity' => __('organization-design.activity_has_standards')]);
        }
        DB::transaction(function () use ($actor, $activity): void {
            WorkItemIo::query()->where('subject_type', $activity->getMorphClass())->where('subject_id', $activity->getKey())->delete();
            $this->audit->record(AuditEventType::ActivityRemoved, $actor, $activity, [], $activity->only(self::ACTIVITY_FIELDS));
            $activity->delete();
        });
    }

    // ── Tasks ────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    public function createTask(User $actor, ProcessActivity $activity, array $data): ProcessTask
    {
        $this->assertEditable($activity->process);
        $task = new ProcessTask([
            ...array_intersect_key($data, array_flip(self::TASK_FIELDS)),
            'activity_id' => $activity->getKey(),
            'process_id' => $activity->process_id,
            'organization_id' => $activity->organization_id,
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ]);
        $task->save();
        $this->audit->record(AuditEventType::TaskSaved, $actor, $task, $task->only(self::TASK_FIELDS));

        return $task;
    }

    /** @param array<string, mixed> $data */
    public function updateTask(User $actor, ProcessTask $task, array $data): ProcessTask
    {
        $this->assertEditable($task->process);
        $old = $task->only(self::TASK_FIELDS);
        $task->fill([...array_intersect_key($data, array_flip(self::TASK_FIELDS)), 'updated_by' => $actor->getKey()])->save();
        $this->audit->record(AuditEventType::TaskSaved, $actor, $task, $task->only(self::TASK_FIELDS), $old);

        return $task;
    }

    public function removeTask(User $actor, ProcessTask $task): void
    {
        $this->assertEditable($task->process);
        if ($task->standards()->exists()) {
            throw ValidationException::withMessages(['task' => __('organization-design.task_has_standards')]);
        }
        if ($task->positionTasks()->exists()) {
            throw ValidationException::withMessages(['task' => __('organization-design.task_has_position_tasks')]);
        }
        DB::transaction(function () use ($actor, $task): void {
            WorkItemIo::query()->where('subject_type', $task->getMorphClass())->where('subject_id', $task->getKey())->delete();
            $this->audit->record(AuditEventType::TaskRemoved, $actor, $task, [], $task->only(self::TASK_FIELDS));
            $task->delete();
        });
    }

    // ── Typed I/O rows ───────────────────────────────────────────────────

    /**
     * Replace the typed rows of a subject. A dependency may name an
     * activity of the same process.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function syncIo(User $actor, Process|ProcessActivity|ProcessTask $subject, array $items): void
    {
        $process = $subject instanceof Process ? $subject : $subject->process;
        $this->assertEditable($process);

        $activityIds = ProcessActivity::query()->where('process_id', $process->getKey())->pluck('id')->flip();
        foreach ($items as $index => $item) {
            $dependsOn = $item['depends_on_activity_id'] ?? null;
            if ($dependsOn !== null && ! $activityIds->has($dependsOn)) {
                throw ValidationException::withMessages(["items.{$index}.depends_on_activity_id" => __('organization-design.io_dependency_foreign')]);
            }
        }

        DB::transaction(function () use ($actor, $subject, $process, $items): void {
            WorkItemIo::query()->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())->delete();
            foreach (array_values($items) as $index => $item) {
                WorkItemIo::query()->create([
                    'subject_type' => $subject->getMorphClass(),
                    'subject_id' => $subject->getKey(),
                    'organization_id' => $process->organization_id,
                    'kind' => $item['kind'],
                    'label_en' => $item['label_en'],
                    'label_am' => $item['label_am'] ?? null,
                    'description' => $item['description'] ?? null,
                    'depends_on_activity_id' => $item['kind'] === 'dependency' ? ($item['depends_on_activity_id'] ?? null) : null,
                    'sequence' => $index,
                ]);
            }
            $this->audit->record(AuditEventType::WorkItemIoSynced, $actor, $subject, ['rows' => count($items)]);
        });
    }

    // ── Daily Work bridge ────────────────────────────────────────────────

    /** Trace a position task to a master task of the same organization, or clear the trace. */
    public function linkPositionTask(User $actor, PositionServiceTask $positionTask, ?string $masterTaskId): PositionServiceTask
    {
        $master = $masterTaskId === null ? null : ProcessTask::query()->find($masterTaskId);
        if ($masterTaskId !== null && ($master === null || $master->organization_id !== $positionTask->organization_id)) {
            throw ValidationException::withMessages(['master_task_id' => __('organization-design.master_task_foreign')]);
        }
        $old = $positionTask->master_task_id;
        $positionTask->forceFill(['master_task_id' => $master?->getKey(), 'updated_by' => $actor->getKey()])->save();
        $this->audit->record(AuditEventType::PositionTaskLinked, $actor, $positionTask, ['master_task_id' => $master?->getKey()], ['master_task_id' => $old]);

        return $positionTask;
    }

    // ── Reads ────────────────────────────────────────────────────────────

    /**
     * The activity tree of a process with tasks, I/O rows and standards, as
     * nested arrays, from a handful of queries.
     *
     * @return list<array<string, mixed>>
     */
    public function tree(Process $process): array
    {
        $activities = ProcessActivity::query()->where('process_id', $process->getKey())->orderBy('path')->get();
        $tasks = ProcessTask::query()->where('process_id', $process->getKey())->orderBy('sequence')->orderBy('code')->get()->groupBy('activity_id');
        $io = WorkItemIo::query()->where('organization_id', $process->organization_id)
            ->whereIn('subject_id', [...$activities->pluck('id')->all(), ...$tasks->flatten()->pluck('id')->all()])
            ->orderBy('sequence')->get()->groupBy('subject_id');
        $standards = PositionServiceTaskStandard::query()
            ->where(fn ($q) => $q->whereIn('master_task_id', $tasks->flatten()->pluck('id')->all())->orWhereIn('activity_id', $activities->pluck('id')->all()))
            ->orderByDesc('version_no')->get();
        $byTask = $standards->whereNotNull('master_task_id')->groupBy('master_task_id');
        $byActivity = $standards->whereNotNull('activity_id')->groupBy('activity_id');

        $nodes = [];
        foreach ($activities as $activity) {
            $nodes[$activity->id] = [
                ...$activity->only(['id', 'code', 'name_en', 'name_am', 'description_en', 'description_am', 'sequence', 'depth', 'is_active', 'parent_activity_id']),
                'io' => $this->ioRows($io->get($activity->id)),
                'standards' => $this->standardRows($byActivity->get($activity->id)),
                'tasks' => collect($tasks->get($activity->id, []))->map(fn (ProcessTask $task) => [
                    ...$task->only(['id', 'code', 'name_en', 'name_am', 'description_en', 'description_am', 'sequence', 'required_output', 'is_active']),
                    'io' => $this->ioRows($io->get($task->id)),
                    'standards' => $this->standardRows($byTask->get($task->id)),
                ])->values()->all(),
                'children' => [],
            ];
        }
        $roots = [];
        foreach ($activities as $activity) {
            if ($activity->parent_activity_id !== null && isset($nodes[$activity->parent_activity_id])) {
                $nodes[$activity->parent_activity_id]['children'][] = &$nodes[$activity->id];
            } else {
                $roots[] = &$nodes[$activity->id];
            }
        }
        $sort = function (array &$list) use (&$sort): void {
            usort($list, fn ($a, $b) => [$a['sequence'], $a['code']] <=> [$b['sequence'], $b['code']]);
            foreach ($list as &$node) {
                $sort($node['children']);
            }
        };
        $sort($roots);

        return $roots;
    }

    /** @return list<array<string, mixed>> */
    public function ioRows($rows): array
    {
        return collect($rows ?? [])->map(fn (WorkItemIo $row) => [
            'id' => $row->id, 'kind' => $row->kind->value, 'label_en' => $row->label_en, 'label_am' => $row->label_am,
            'description' => $row->description, 'depends_on_activity_id' => $row->depends_on_activity_id, 'sequence' => $row->sequence,
        ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function standardRows($rows): array
    {
        return collect($rows ?? [])->map(fn (PositionServiceTaskStandard $s) => [
            'id' => $s->id, 'version_no' => $s->version_no, 'status' => $s->status->value,
            'standard_measure' => $s->standard_measure, 'bpr_reference' => $s->bpr_reference,
            'planned_quantity' => $s->planned_quantity, 'quantity_unit' => $s->quantity_unit, 'planned_time_minutes' => $s->planned_time_minutes,
            'planned_quality' => $s->planned_quality, 'quality_unit' => $s->quality_unit, 'quality_measure' => $s->quality_measure, 'quality_source' => $s->quality_source?->value,
            'effective_from' => $s->effective_from?->toDateString(), 'effective_to' => $s->effective_to?->toDateString(),
        ])->values()->all();
    }

    // ── Internals ────────────────────────────────────────────────────────

    private function copyTree(User $actor, Process $from, Process $to): void
    {
        $map = [];
        $activities = ProcessActivity::query()->where('process_id', $from->getKey())->orderBy('path')->get();
        foreach ($activities as $activity) {
            $parentId = $activity->parent_activity_id === null ? null : ($map[$activity->parent_activity_id] ?? null);
            $copy = new ProcessActivity([
                ...$activity->only(self::ACTIVITY_FIELDS),
                'process_id' => $to->getKey(), 'organization_id' => $to->organization_id, 'parent_activity_id' => $parentId,
                'depth' => $activity->depth, 'path' => '', 'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
            ]);
            $copy->save();
            $parentPath = $parentId === null ? null : ProcessActivity::query()->whereKey($parentId)->value('path');
            $copy->forceFill(['path' => $parentPath ? $parentPath.'/'.$copy->getKey() : (string) $copy->getKey()])->save();
            $map[$activity->getKey()] = $copy->getKey();
        }
        $tasks = ProcessTask::query()->where('process_id', $from->getKey())->get();
        foreach ($tasks as $task) {
            $copy = new ProcessTask([
                ...$task->only(self::TASK_FIELDS),
                'activity_id' => $map[$task->activity_id], 'process_id' => $to->getKey(), 'organization_id' => $to->organization_id,
                'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
            ]);
            $copy->save();
            $map[$task->getKey()] = $copy->getKey();
        }
        $map[$from->getKey()] = $to->getKey();

        $rows = WorkItemIo::query()->whereIn('subject_id', array_keys($map))->orderBy('sequence')->get();
        foreach ($rows as $row) {
            WorkItemIo::query()->create([
                ...$row->only(['subject_type', 'organization_id', 'kind', 'label_en', 'label_am', 'description', 'sequence']),
                'subject_id' => $map[$row->subject_id],
                'depends_on_activity_id' => $row->depends_on_activity_id === null ? null : ($map[$row->depends_on_activity_id] ?? null),
            ]);
        }
    }

    private function parentFor(Process $process, ?string $parentId): ?ProcessActivity
    {
        if ($parentId === null || $parentId === '') {
            return null;
        }
        $parent = ProcessActivity::query()->whereKey($parentId)->where('process_id', $process->getKey())->first();
        if ($parent === null) {
            throw ValidationException::withMessages(['parent_activity_id' => __('organization-design.activity_parent_invalid')]);
        }

        return $parent;
    }

    /** Re-parent an activity and rewrite the path and depth of its subtree. */
    private function moveSubtree(ProcessActivity $activity, ?ProcessActivity $parent): void
    {
        $oldPath = $activity->path;
        $oldDepth = $activity->depth;
        $newPath = $parent ? $parent->path.'/'.$activity->getKey() : (string) $activity->getKey();
        $newDepth = $parent ? $parent->depth + 1 : 0;

        $activity->parent_activity_id = $parent?->getKey();
        $activity->path = $newPath;
        $activity->depth = $newDepth;

        $descendants = ProcessActivity::query()->where('process_id', $activity->process_id)->where('path', 'like', $oldPath.'/%')->lockForUpdate()->get();
        foreach ($descendants as $node) {
            $node->forceFill([
                'path' => $newPath.substr($node->path, strlen($oldPath)),
                'depth' => $node->depth - $oldDepth + $newDepth,
            ])->save();
        }
    }

    private function assertEditable(Process $process): void
    {
        if (! $process->isEditable()) {
            throw ValidationException::withMessages(['status' => __('organization-design.process_not_draft')]);
        }
    }

    private function assertOwnService(string $organizationId, ?string $serviceId): void
    {
        if ($serviceId === null || ! Service::query()->whereKey($serviceId)->where('organization_id', $organizationId)->exists()) {
            throw ValidationException::withMessages(['service_id' => __('organization-design.process_service_foreign')]);
        }
    }
}
