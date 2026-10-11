<?php

declare(strict_types=1);

namespace App\Http\Controllers\OrganizationDesign;

use App\Enums\OrganizationDesignStatus;
use App\Enums\ProcessType;
use App\Enums\TaskQualitySource;
use App\Enums\WorkItemIoKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationDesign\NewProcessVersionRequest;
use App\Http\Requests\OrganizationDesign\StoreProcessRequest;
use App\Http\Requests\OrganizationDesign\UpdateProcessRequest;
use App\Models\Organization;
use App\Models\OrganizationDesignStatusHistory;
use App\Models\OrganizationUnit;
use App\Models\Process;
use App\Models\Service;
use App\Services\OrganizationDesign\ProcessArchitectureService;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProcessController extends Controller
{
    public function __construct(private readonly ProcessArchitectureService $processes, private readonly OrganizationScopeService $scope) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Process::class);
        $user = $request->user();

        $query = Process::query()
            ->with(['organization:id,code,name_en,name_am', 'service:id,code,name_en,name_am', 'ownerUnit:id,code,name_en,name_am'])
            ->withCount(['activities', 'tasks'])
            ->when($request->string('organization_id')->toString(), fn ($q, $id) => $q->where('organization_id', $id))
            ->when($request->string('service_id')->toString(), fn ($q, $id) => $q->where('service_id', $id))
            ->when($request->string('status')->toString(), fn ($q, $status) => $q->where('status', $status))
            ->when($request->string('process_type')->toString(), fn ($q, $type) => $q->where('process_type', $type))
            ->when($request->string('search')->toString(), function ($q, $search): void {
                $q->where(function ($nested) use ($search): void {
                    $nested->where('code', ci_like_operator(), "%{$search}%")
                        ->orWhere('name_en', ci_like_operator(), "%{$search}%")
                        ->orWhere('name_am', ci_like_operator(), "%{$search}%");
                });
            })
            ->orderBy('code')->orderByDesc('version_no');
        $this->scope->applyOrganizationScope($query, $user);

        $services = Service::query()->whereIn('status', [OrganizationDesignStatus::Published->value, OrganizationDesignStatus::Draft->value])->orderBy('code');
        $this->scope->applyOrganizationScope($services, $user);

        return Inertia::render('OrganizationDesign/Processes/Index', [
            'processes' => $query->paginate(25)->withQueryString()->through(fn (Process $p) => $this->summary($p)),
            'filters' => $request->only(['organization_id', 'service_id', 'status', 'process_type', 'search']),
            'organizations' => $this->organizationOptions($user),
            'services' => $services->limit(1000)->get(['id', 'organization_id', 'code', 'name_en', 'name_am', 'version_no', 'status'])
                ->map(fn (Service $s) => ['id' => $s->id, 'organization_id' => $s->organization_id, 'code' => $s->code, 'name_en' => $s->name_en, 'name_am' => $s->name_am, 'version_no' => $s->version_no, 'status' => $s->status->value]),
            'statuses' => array_map(fn (OrganizationDesignStatus $s) => $s->value, OrganizationDesignStatus::cases()),
            'processTypes' => ProcessType::values(),
            'can' => ['create' => $user->can('create', Process::class)],
        ]);
    }

    public function store(StoreProcessRequest $request): RedirectResponse
    {
        $process = $this->processes->createDraft($request->user(), $request->validated('organization_id'), $request->validated());

        return to_route('organization-design.processes.show', $process)->with('flash', ['message' => __('organization-design.process_created'), 'type' => 'success']);
    }

    public function show(Request $request, Process $process): Response
    {
        $this->authorize('view', $process);
        $user = $request->user();
        $process->load(['organization:id,code,name_en,name_am', 'service:id,code,name_en,name_am,version_no', 'ownerUnit:id,code,name_en,name_am', 'approver:id,name', 'creator:id,name']);
        $editable = $process->isEditable();

        return Inertia::render('OrganizationDesign/Processes/Show', [
            'process' => [
                ...$this->summary($process),
                'description_en' => $process->description_en, 'description_am' => $process->description_am,
                'owner_organization_unit_id' => $process->owner_organization_unit_id,
                'approved_by' => $process->approver?->name, 'approved_at' => $process->approved_at?->toISOString(), 'created_by' => $process->creator?->name,
                'io' => $this->processes->ioRows($process->ioRows()->get()),
            ],
            'activities' => $this->processes->tree($process),
            'versions' => $process->versions()->get(['id', 'version_no', 'status', 'effective_from', 'effective_to'])
                ->map(fn (Process $v) => ['id' => $v->id, 'version_no' => $v->version_no, 'status' => $v->status->value, 'effective_from' => $v->effective_from?->toDateString(), 'effective_to' => $v->effective_to?->toDateString()]),
            'history' => OrganizationDesignStatusHistory::query()->where('entity_type', $process->getMorphClass())->where('entity_id', $process->getKey())
                ->with('actor:id,name')->orderByDesc('created_at')->get()
                ->map(fn ($h) => ['from' => $h->from_status, 'to' => $h->to_status, 'actor' => $h->actor?->name, 'reason' => $h->reason, 'at' => $h->created_at->toISOString()]),
            'services' => Service::query()->where('organization_id', $process->organization_id)->orderBy('code')->get(['id', 'code', 'name_en', 'name_am', 'version_no'])->map->only(['id', 'code', 'name_en', 'name_am', 'version_no']),
            'organizationUnits' => OrganizationUnit::query()->where('organization_id', $process->organization_id)->orderBy('name_en')->limit(500)->get(['id', 'code', 'name_en', 'name_am'])->map->only(['id', 'code', 'name_en', 'name_am']),
            'processTypes' => ProcessType::values(),
            'ioKinds' => WorkItemIoKind::values(),
            'qualitySources' => TaskQualitySource::values(),
            'can' => [
                'update' => $editable && $user->can('update', $process),
                'publish' => $editable && $user->can('publish', $process) && (int) $process->created_by !== (int) $user->getKey(),
                'version' => $process->status === OrganizationDesignStatus::Published && $user->can('version', $process),
                'manageStandards' => $user->can('manageStandards', $process),
                'approveStandards' => $user->can('approveStandards', $process),
            ],
        ]);
    }

    public function update(UpdateProcessRequest $request, Process $process): RedirectResponse
    {
        $this->processes->updateDraft($request->user(), $process, $request->validated());

        return back()->with('flash', ['message' => __('organization-design.process_updated'), 'type' => 'success']);
    }

    public function storeVersion(NewProcessVersionRequest $request, Process $process): RedirectResponse
    {
        $draft = $this->processes->newVersion($request->user(), $process, $request->validated());

        return to_route('organization-design.processes.show', $draft)->with('flash', ['message' => __('organization-design.process_version_created'), 'type' => 'success']);
    }

    public function publish(Request $request, Process $process): RedirectResponse
    {
        $this->authorize('publish', $process);
        $this->processes->publish($request->user(), $process);

        return back()->with('flash', ['message' => __('organization-design.process_published'), 'type' => 'success']);
    }

    public function cancel(Request $request, Process $process): RedirectResponse
    {
        $this->authorize('update', $process);
        $this->processes->cancel($request->user(), $process, $request->string('reason')->toString() ?: null);

        return to_route('organization-design.processes.index')->with('flash', ['message' => __('organization-design.process_cancelled'), 'type' => 'success']);
    }

    /** @return array<string, mixed> */
    private function summary(Process $p): array
    {
        return [
            'id' => $p->id, 'code' => $p->code, 'version_no' => $p->version_no, 'lineage_key' => $p->lineage_key,
            'name_en' => $p->name_en, 'name_am' => $p->name_am, 'status' => $p->status->value, 'process_type' => $p->process_type->value,
            'service_id' => $p->service_id,
            'service' => $p->service ? ['id' => $p->service->id, 'code' => $p->service->code, 'name_en' => $p->service->name_en, 'name_am' => $p->service->name_am] : null,
            'organization' => $p->organization ? ['id' => $p->organization->id, 'code' => $p->organization->code, 'name_en' => $p->organization->name_en, 'name_am' => $p->organization->name_am] : null,
            'organization_id' => $p->organization_id,
            'owner_unit' => $p->ownerUnit ? ['id' => $p->ownerUnit->id, 'code' => $p->ownerUnit->code, 'name_en' => $p->ownerUnit->name_en, 'name_am' => $p->ownerUnit->name_am] : null,
            'activities_count' => $p->activities_count ?? null, 'tasks_count' => $p->tasks_count ?? null,
            'effective_from' => $p->effective_from?->toDateString(), 'effective_to' => $p->effective_to?->toDateString(),
        ];
    }

    /** @return list<array{id: string, code: string, name_en: string, name_am: string|null}> */
    private function organizationOptions($user): array
    {
        $query = Organization::query()->orderBy('name_en');
        $this->scope->applyOrganizationScope($query, $user, 'id');

        return $query->limit(500)->get(['id', 'code', 'name_en', 'name_am'])->map->only(['id', 'code', 'name_en', 'name_am'])->all();
    }
}
