<?php

declare(strict_types=1);

namespace App\Http\Controllers\Performance;

use App\Enums\Performance\CycleStatus;
use App\Http\Requests\Performance\StorePerformanceCycleRequest;
use App\Models\Organization;
use App\Models\PerformanceCycle;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\Performance\PerformanceCycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PerformanceCycleController extends PerformanceController
{
    public function __construct(private readonly PerformanceCycleService $cycles, private readonly OrganizationScopeService $scope) {}

    public function index(Request $request): Response
    {
        $this->ensureEnabled();
        $user = $request->user();
        abort_unless($user->can('performance_cycles.view'), 403);

        $cycles = PerformanceCycle::query()->with('organization:id,name_en,name_am')
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhereIn('organization_id', $this->scope->allowedOrganizationIds($user)))
            ->orderByDesc('start_date')->paginate(20)->withQueryString();

        return Inertia::render('Performance/Cycles/Index', [
            'cycles' => $cycles->through(fn (PerformanceCycle $c) => [
                'id' => $c->getKey(), 'code' => $c->code, 'name_en' => $c->name_en, 'name_am' => $c->name_am,
                'organization' => $c->organization ? ['name_en' => $c->organization->name_en, 'name_am' => $c->organization->name_am] : null,
                'start_date' => $c->start_date->toDateString(), 'end_date' => $c->end_date->toDateString(),
                'midyear' => [$c->midyear_review_start_date?->toDateString(), $c->midyear_review_end_date?->toDateString()],
                'yearend' => [$c->yearend_review_start_date?->toDateString(), $c->yearend_review_end_date?->toDateString()],
                'status' => $c->status->value, 'is_current' => $c->is_current,
                'read_only' => PerformanceCycleService::isReadOnly($c),
                'next' => PerformanceCycleService::nextStatuses($c),
            ]),
            'statuses' => CycleStatus::values(),
            'organizations' => $this->scope->applyOrganizationScope(Organization::query(), $user, 'id')->orderBy('name_en')->limit(200)->get(['id', 'name_en', 'name_am'])->toArray(),
            'canCreateGlobal' => $user->hasAnyRole(['Super Admin', 'City Admin', 'System Admin']),
            'can' => [
                'create' => $user->can('performance_cycles.create'),
                'update' => $user->can('performance_cycles.update'),
                'activate' => $user->can('performance_cycles.activate'),
                'close' => $user->can('performance_cycles.close'),
            ],
        ]);
    }

    public function store(StorePerformanceCycleRequest $request): RedirectResponse
    {
        $this->ensureEnabled();
        $this->cycles->create($request->validated(), $request->user());

        return $this->saved();
    }

    public function transition(Request $request, PerformanceCycle $cycle): RedirectResponse
    {
        $this->ensureEnabled();
        $data = $request->validate(['status' => ['required', Rule::enum(CycleStatus::class)]]);
        $this->cycles->transition($cycle, CycleStatus::from($data['status']), $request->user());

        return $this->saved();
    }
}
