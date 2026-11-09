<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign;

use App\Models\Organization;
use App\Models\Position;
use App\Models\PositionServiceTask;
use App\Models\PositionWorkLink;
use App\Services\OrganizationDesign\Concerns\BuildsDesignFindings;

/**
 * Data quality of the bridge between existing records and the design
 * (brief §117–118): how much is still unmapped, what needs a person's
 * review, and links that point at inactive or missing design objects.
 * Never fixes anything.
 */
class DataQualityService
{
    use BuildsDesignFindings;

    public function __construct(private readonly MappingWorkspaceService $mapping) {}

    /** @return array{ok: bool, summary: array{errors: int, warnings: int, infos: int}, findings: list<array<string, mixed>>, generated_at: string} */
    public function check(string $organizationId): array
    {
        $organization = Organization::query()->findOrFail($organizationId);
        $orgSubject = ['type' => 'organization', 'id' => $organization->id, 'code' => $organization->code, 'name' => $organization->name_en];
        $unmapped = $this->mapping->unmapped($organizationId);
        $findings = [];

        if (($n = count($unmapped['position_tasks'])) > 0) {
            $findings[] = $this->finding('info', 'unmapped_position_tasks', $orgSubject, ['count' => $n]);
        }
        foreach ($unmapped['position_tasks'] as $task) {
            if ($task['needs_review']) {
                $findings[] = $this->finding('info', 'needs_data_review', ['type' => 'position_task', 'id' => $task['id'], 'code' => $task['code'], 'name' => $task['name_en']], ['candidates' => count($task['suggestions'])]);
            }
        }
        if (($n = count($unmapped['positions'])) > 0) {
            $findings[] = $this->finding('info', 'unmapped_positions', $orgSubject, ['count' => $n]);
        }
        if (($n = count($unmapped['units'])) > 0) {
            $findings[] = $this->finding('info', 'unmapped_units', $orgSubject, ['count' => $n]);
        }

        // Legacy tasks mapped to a master that is no longer live, or that belongs elsewhere.
        $mapped = PositionServiceTask::query()->where('organization_id', $organizationId)->whereNotNull('master_task_id')->with('masterTask:id,code,name_en,organization_id,is_active')->orderBy('code')->limit(5000)->get();
        foreach ($mapped as $task) {
            $subject = ['type' => 'position_task', 'id' => $task->id, 'code' => $task->code, 'name' => $task->name_en];
            if ($task->masterTask === null || $task->masterTask->organization_id !== $organizationId) {
                $findings[] = $this->finding('error', 'position_task_master_foreign', $subject, ['master' => $task->masterTask?->code]);
            } elseif (! $task->masterTask->is_active) {
                $findings[] = $this->finding('warning', 'position_task_master_inactive', $subject, ['master' => $task->masterTask->code]);
            }
        }

        // Position work links whose object is gone or inactive.
        $links = PositionWorkLink::query()->where('organization_id', $organizationId)->orderBy('sequence')->limit(10000)->get();
        $positions = Position::query()->whereIn('id', $links->pluck('position_id')->unique())->get(['id', 'job_position_code', 'title_en'])->keyBy('id');
        $objects = [];
        foreach (PositionWorkLink::OBJECT_MODELS as $type => $model) {
            $ids = $links->where('object_type', $type)->pluck('object_id')->unique();
            $objects[$type] = $ids->isEmpty() ? collect() : $model::query()->whereIn('id', $ids)->get()->keyBy('id');
        }
        foreach ($links as $link) {
            $object = $objects[$link->object_type]?->get($link->object_id);
            $live = $object !== null && (! array_key_exists('is_active', $object->getAttributes()) || (bool) $object->is_active) && (! isset($object->status) || ! in_array($object->status instanceof \BackedEnum ? $object->status->value : $object->status, ['cancelled'], true));
            if (! $live) {
                $position = $positions->get($link->position_id);
                $findings[] = $this->finding('warning', 'work_link_object_missing', ['type' => 'position', 'id' => $link->position_id, 'code' => $position?->job_position_code, 'name' => $position?->title_en], ['object_type' => $link->object_type, 'object_id' => $link->object_id]);
            }
        }

        return $this->report($findings);
    }
}
