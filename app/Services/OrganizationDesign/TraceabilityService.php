<?php

declare(strict_types=1);

namespace App\Services\OrganizationDesign;

use App\Enums\OrganizationDesignStatus;
use App\Models\InstitutionMandate;
use App\Models\JobDescription;
use App\Models\OrganizationFunction;
use App\Models\Position;
use App\Models\PositionWorkLink;
use App\Models\Process;
use App\Models\ProcessActivity;
use App\Models\ProcessTask;
use App\Models\Service;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * End-to-end traceability (brief §76–78): mandate → function → service →
 * process → activity → task → position → job description. Nothing here is
 * inferred; a link exists only where the design recorded it.
 */
class TraceabilityService
{
    /** @var array<string, class-string<Model>> */
    public const TYPES = [
        'mandate' => InstitutionMandate::class, 'function' => OrganizationFunction::class, 'service' => Service::class, 'process' => Process::class,
        'activity' => ProcessActivity::class, 'task' => ProcessTask::class, 'position' => Position::class, 'job_description' => JobDescription::class,
    ];

    /**
     * One object with everything above it (top first) and everything below it.
     *
     * @return array{subject: array<string, mixed>, upstream: list<array<string, mixed>>, downstream: list<array<string, mixed>>}
     */
    public function trace(string $type, string $id): array
    {
        $model = self::TYPES[$type] ?? abort(404);
        $subject = $model::query()->findOrFail($id);

        return ['subject' => $this->node($type, $subject), 'upstream' => $this->upstream($type, $subject), 'downstream' => $this->downstream($type, $subject)];
    }

    /**
     * One row per task of the organization with the whole chain around it;
     * tasks nobody links to still appear, with empty position columns.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(string $organizationId): array
    {
        $tasks = ProcessTask::query()->where('organization_id', $organizationId)->where('is_active', true)
            ->with(['activity:id,code,name_en', 'process:id,code,name_en,service_id,status'])->orderBy('code')->get();
        $services = Service::query()->whereIn('id', $tasks->pluck('process.service_id')->filter()->unique())->with(['functions:id,code,title_en,mandate_id', 'functions.mandate:id,code,title_en'])->get()->keyBy('id');
        $links = PositionWorkLink::query()->where('organization_id', $organizationId)->whereIn('object_type', ['task', 'activity'])->get()->groupBy(fn ($l) => $l->object_type.':'.$l->object_id);
        $positions = Position::query()->whereIn('id', $links->flatten()->pluck('position_id')->unique())->where('is_active', true)->get(['id', 'job_position_code', 'title_en'])->keyBy('id');
        $jds = $this->jobDescriptionsInForce($positions->keys()->all());

        return $tasks->map(function (ProcessTask $task) use ($services, $links, $positions, $jds): array {
            $service = $services->get($task->process?->service_id);
            $functions = $service?->functions ?? collect();
            $positionIds = collect([...($links->get('task:'.$task->id) ?? []), ...($links->get('activity:'.$task->activity_id) ?? [])])->pluck('position_id')->unique()->filter(fn ($id) => $positions->has($id));

            return [
                'mandate_code' => $functions->map(fn ($f) => $f->mandate?->code)->filter()->unique()->implode(', '),
                'function_code' => $functions->pluck('code')->implode(', '),
                'service_code' => $service?->code,
                'process_code' => $task->process?->code,
                'activity_code' => $task->activity?->code,
                'task_code' => $task->code,
                'task_name' => $task->name_en,
                'positions' => $positionIds->map(fn ($id) => $positions[$id]->job_position_code)->implode(', '),
                'job_descriptions' => $positionIds->map(fn ($id) => $jds->get($id)?->reference_number)->filter()->implode(', '),
            ];
        })->all();
    }

    /** @return list<array<string, mixed>> */
    private function upstream(string $type, Model $subject): array
    {
        $nodes = [];
        $add = function (string $t, ?Model $m) use (&$nodes): void {
            if ($m !== null && ! isset($nodes[$t.':'.$m->getKey()])) {
                $nodes[$t.':'.$m->getKey()] = $this->node($t, $m);
            }
        };
        $climbService = function (?Service $service) use ($add): void {
            if ($service === null) {
                return;
            }
            foreach ($service->functions()->with('mandate')->get() as $function) {
                $add('mandate', $function->mandate);
                $add('function', $function);
            }
            $add('service', $service);
        };
        $climbTask = function (?ProcessTask $task) use ($add, $climbService): void {
            if ($task === null) {
                return;
            }
            $climbService($task->process?->service);
            $add('process', $task->process);
            $add('activity', $task->activity);
        };

        match ($type) {
            'function' => $add('mandate', $subject->mandate),
            'service' => (function () use ($subject, $add): void {
                foreach ($subject->functions()->with('mandate')->get() as $function) {
                    $add('mandate', $function->mandate);
                    $add('function', $function);
                }
            })(),
            'process' => $climbService($subject->service),
            'activity' => (function () use ($subject, $add, $climbService): void {
                $climbService($subject->process?->service);
                $add('process', $subject->process);
            })(),
            'task' => $climbTask($subject),
            'position' => (function () use ($subject, $add, $climbService, $climbTask): void {
                foreach (PositionWorkLink::query()->where('position_id', $subject->getKey())->orderBy('sequence')->get() as $link) {
                    $object = $link->object();
                    match ($link->object_type) {
                        'service' => $climbService($object),
                        'process' => (function () use ($object, $add, $climbService): void {
                            $climbService($object?->service);
                            $add('process', $object);
                        })(),
                        'activity' => (function () use ($object, $add, $climbService): void {
                            $climbService($object?->process?->service);
                            $add('process', $object?->process);
                            $add('activity', $object);
                        })(),
                        'task' => (function () use ($object, $add, $climbTask): void {
                            $climbTask($object);
                            $add('task', $object);
                        })(),
                        default => null,
                    };
                }
            })(),
            'job_description' => (function () use ($subject, $add): void {
                $add('position', $subject->position);
            })(),
            default => null,
        };
        $order = array_flip(array_keys(self::TYPES));
        $list = array_values($nodes);
        usort($list, fn ($a, $b) => [$order[$a['type']], $a['code']] <=> [$order[$b['type']], $b['code']]);

        return $list;
    }

    /** @return list<array<string, mixed>> */
    private function downstream(string $type, Model $subject): array
    {
        $nodes = collect();
        $positionsFor = function (string $objectType, array $ids) use (&$nodes): void {
            if ($ids === []) {
                return;
            }
            $positionIds = PositionWorkLink::query()->where('object_type', $objectType)->whereIn('object_id', $ids)->pluck('position_id')->unique()->all();
            foreach (Position::query()->whereIn('id', $positionIds)->where('is_active', true)->orderBy('job_position_code')->get() as $position) {
                $nodes->put('position:'.$position->id, $this->node('position', $position));
            }
            foreach ($this->jobDescriptionsInForce($positionIds) as $jd) {
                $nodes->put('job_description:'.$jd->id, $this->node('job_description', $jd));
            }
        };

        switch ($type) {
            case 'mandate':
                $functions = OrganizationFunction::query()->where('mandate_id', $subject->getKey())->orderBy('path')->get();
                $functions->each(fn ($f) => $nodes->put('function:'.$f->id, $this->node('function', $f)));
                $services = Service::query()->whereHas('functions', fn ($q) => $q->whereIn('organization_functions.id', $functions->pluck('id')))->where('status', OrganizationDesignStatus::Published)->orderBy('code')->get();
                $services->each(fn ($s) => $nodes->put('service:'.$s->id, $this->node('service', $s)));
                break;
            case 'function':
                $services = $subject->services()->where('status', OrganizationDesignStatus::Published)->orderBy('code')->get();
                $services->each(fn ($s) => $nodes->put('service:'.$s->id, $this->node('service', $s)));
                $processes = Process::query()->whereIn('service_id', $services->pluck('id'))->where('status', OrganizationDesignStatus::Published)->orderBy('code')->get();
                $processes->each(fn ($p) => $nodes->put('process:'.$p->id, $this->node('process', $p)));
                $positionsFor('service', $services->pluck('id')->all());
                $positionsFor('process', $processes->pluck('id')->all());
                break;
            case 'service':
                $processes = Process::query()->where('service_id', $subject->getKey())->where('status', OrganizationDesignStatus::Published)->orderBy('code')->get();
                $processes->each(fn ($p) => $nodes->put('process:'.$p->id, $this->node('process', $p)));
                $positionsFor('service', [$subject->getKey()]);
                $positionsFor('process', $processes->pluck('id')->all());
                break;
            case 'process':
                $activities = ProcessActivity::query()->where('process_id', $subject->getKey())->orderBy('path')->get();
                $activities->each(fn ($a) => $nodes->put('activity:'.$a->id, $this->node('activity', $a)));
                $tasks = ProcessTask::query()->where('process_id', $subject->getKey())->orderBy('code')->get();
                $tasks->each(fn ($t) => $nodes->put('task:'.$t->id, $this->node('task', $t)));
                $positionsFor('process', [$subject->getKey()]);
                $positionsFor('activity', $activities->pluck('id')->all());
                $positionsFor('task', $tasks->pluck('id')->all());
                break;
            case 'activity':
                $tasks = ProcessTask::query()->where('activity_id', $subject->getKey())->orderBy('code')->get();
                $tasks->each(fn ($t) => $nodes->put('task:'.$t->id, $this->node('task', $t)));
                $positionsFor('activity', [$subject->getKey()]);
                $positionsFor('task', $tasks->pluck('id')->all());
                break;
            case 'task':
                $positionsFor('task', [$subject->getKey()]);
                break;
            case 'position':
                foreach ($this->jobDescriptionsInForce([$subject->getKey()]) as $jd) {
                    $nodes->put('job_description:'.$jd->id, $this->node('job_description', $jd));
                }
                break;
        }

        return $nodes->values()->all();
    }

    /** @return Collection<string, JobDescription> keyed by position id */
    private function jobDescriptionsInForce(array $positionIds): Collection
    {
        if ($positionIds === []) {
            return collect();
        }
        $today = now()->toDateString();

        return JobDescription::query()->whereIn('position_id', $positionIds)
            ->whereIn('status', [OrganizationDesignStatus::Published->value, OrganizationDesignStatus::Superseded->value])
            ->whereDate('effective_from', '<=', $today)->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
            ->orderByDesc('version_no')->get()->unique('position_id')->keyBy('position_id');
    }

    /** @return array{type: string, id: string, code: string|null, name: string|null, name_am: string|null, status: string|null} */
    private function node(string $type, Model $model): array
    {
        $status = $model->status ?? null;

        return [
            'type' => $type,
            'id' => (string) $model->getKey(),
            'code' => $model->code ?? $model->job_position_code ?? $model->reference_number ?? null,
            'name' => $model->name_en ?? $model->title_en ?? null,
            'name_am' => $model->name_am ?? $model->title_am ?? null,
            'status' => $status instanceof \BackedEnum ? $status->value : (is_string($status) ? $status : null),
        ];
    }
}
