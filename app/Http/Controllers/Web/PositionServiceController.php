<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\EmployeeServiceFeedback;
use App\Models\Organization;
use App\Models\Position;
use App\Models\PositionService;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD for the services each position provides to the public.
 *
 * Unrelated to ServiceTypeController. That one manages `service_types`, the
 * ENTITLEMENTS catalog — Cafeteria, Health, Transport: what an employee may
 * receive. These rows are work an officer PERFORMS for a client. The two share
 * a word and nothing else, so they have separate tables, routes and screens.
 *
 * Every read and write is scoped through the position's organization, so an
 * Organizational Admin manages only the services their own posts deliver.
 */
class PositionServiceController extends Controller
{
    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly WriteAuditLogAction $writeAuditLogAction,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PositionService::class);

        $user = $request->user();
        $unrestricted = $this->scope->isUnrestricted($user);

        $records = PositionService::query()
            ->with([
                'position:id,organization_id,job_position_code,title_en,title_am',
                'organization:id,name_en,name_am',
            ])
            ->when(! $unrestricted, fn ($query) => $query->forOrganizations(
                $this->scope->accessibleOrganizationIds($user)->all(),
            ))
            ->when($request->filled('organization_id'), fn ($query) => $query->where('organization_id', $request->string('organization_id')->toString()))
            ->when($request->filled('position_id'), fn ($query) => $query->where('position_id', $request->string('position_id')->toString()))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = '%'.$request->string('search')->toString().'%';

                $query->where(function ($nested) use ($search): void {
                    $nested->where('service_no', ci_like_operator(), $search)
                        ->orWhere('name_en', ci_like_operator(), $search)
                        ->orWhere('name_am', ci_like_operator(), $search);
                });
            })
            ->orderBy('sort_order')
            ->orderBy('service_no')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (PositionService $record): array => $this->present($record));

        return Inertia::render('PositionServices/Index', [
            'records' => $records,
            'filters' => $request->only(['organization_id', 'position_id', 'search']),
            'organizations' => $this->scopedOrganizations($user, $unrestricted),
            'can' => [
                'create' => $user->can('create', PositionService::class),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', PositionService::class);

        $user = $request->user();

        return Inertia::render('PositionServices/Create', [
            'organizations' => $this->scopedOrganizations($user, $this->scope->isUnrestricted($user)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', PositionService::class);

        $data = $this->validated($request);

        $position = Position::query()->findOrFail($data['position_id']);

        // The position arrives in the request body, so scope is re-checked
        // against the real record rather than trusted from the form.
        $this->authorize('createForPosition', [PositionService::class, $position]);

        $this->guardDuplicates($data, null);

        $record = PositionService::query()->create($data + [
            'created_by' => $request->user()?->getKey(),
        ]);

        $this->writeAuditLogAction->execute(
            eventType: AuditEventType::ServiceFeedbackSettingsUpdated,
            actor: $request->user(),
            auditable: $record,
            organizationId: $position->organization_id,
            newValues: $data,
            reason: 'Position service created: '.($data['service_no'] ?? ''),
            request: $request,
        );

        return to_route('position-services.index')
            ->with('success', __('Service added to position.'));
    }

    public function edit(Request $request, PositionService $positionService): Response
    {
        $this->authorize('update', $positionService);

        $positionService->load(['position', 'organization']);

        return Inertia::render('PositionServices/Edit', [
            'record' => $this->present($positionService),
            'organizations' => $this->scopedOrganizations(
                $request->user(),
                $this->scope->isUnrestricted($request->user()),
            ),
            'hasFeedback' => $positionService->position !== null
                && $this->feedbackExistsFor($positionService),
        ]);
    }

    public function update(Request $request, PositionService $positionService): RedirectResponse
    {
        $this->authorize('update', $positionService);

        $data = $this->validated($request);

        $position = Position::query()->findOrFail($data['position_id']);
        $this->authorize('createForPosition', [PositionService::class, $position]);

        $this->guardDuplicates($data, $positionService->getKey());

        /*
         * Once clients have rated this service, its number is quoted in stored
         * feedback and performance reports. Renumbering it would orphan that
         * history, so it is locked unless the actor holds the elevated flag
         * permission.
         */
        if (
            $this->feedbackExistsFor($positionService)
            && $data['service_no'] !== $positionService->service_no
            && ! $request->user()->can('renumberAfterFeedback', $positionService)
        ) {
            throw ValidationException::withMessages([
                'service_no' => __('Service ID cannot be changed after feedback exists.'),
            ]);
        }

        $old = $positionService->only(array_keys($data));

        $positionService->update($data);

        $this->writeAuditLogAction->execute(
            eventType: AuditEventType::ServiceFeedbackSettingsUpdated,
            actor: $request->user(),
            auditable: $positionService,
            organizationId: $position->organization_id,
            oldValues: $old,
            newValues: $data,
            reason: 'Position service updated',
            request: $request,
        );

        return to_route('position-services.index')
            ->with('success', __('Service updated.'));
    }

    public function destroy(Request $request, PositionService $positionService): RedirectResponse
    {
        $this->authorize('delete', $positionService);

        /*
         * Detaching a rated service would strand its feedback. Deactivating
         * keeps the history intact and simply removes it from the public form,
         * so that is the only option once ratings exist.
         */
        if ($this->feedbackExistsFor($positionService)) {
            throw ValidationException::withMessages([
                'service_no' => __('This service has feedback and cannot be removed. Deactivate it instead.'),
            ]);
        }

        $organizationId = $positionService->position?->organization_id;

        $this->writeAuditLogAction->execute(
            eventType: AuditEventType::ServiceFeedbackSettingsUpdated,
            actor: $request->user(),
            auditable: $positionService,
            organizationId: $organizationId,
            oldValues: $positionService->toArray(),
            reason: 'Position service removed',
            request: $request,
        );

        $positionService->delete();

        return to_route('position-services.index')
            ->with('success', __('Service removed from position.'));
    }

    /**
     * Positions inside one organization, for the form's dependent picker.
     *
     * Scope is re-applied here: the organization arrives as a query parameter,
     * so a scoped administrator must not be able to enumerate another
     * organization's positions by editing the URL.
     */
    public function positionsForOrganization(Request $request): JsonResponse
    {
        $this->authorize('create', PositionService::class);

        $organizationId = $request->string('organization_id')->toString();

        if ($organizationId === '' || ! $this->scope->canAccessOrganization($request->user(), $organizationId)) {
            return response()->json(['positions' => []]);
        }

        $positions = Position::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->orderBy('title_en')
            ->get(['id', 'job_position_code', 'title_en', 'title_am']);

        return response()->json(['positions' => $positions]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'organization_id' => ['required', 'uuid', Rule::exists('organizations', 'id')],
            'position_id' => ['required', 'uuid', Rule::exists('positions', 'id')],
            'service_no' => ['required', 'string', 'max:40'],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
            'is_performance_evaluation_enabled' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);
    }

    /**
     * Enforce the two per-position uniqueness rules before hitting the database,
     * so the user sees a field error rather than a constraint violation.
     *
     * @param  array<string, mixed>  $data
     */
    private function guardDuplicates(array $data, ?string $ignoreId): void
    {
        $numberTaken = PositionService::query()
            ->where('position_id', $data['position_id'])
            ->where('service_no', $data['service_no'])
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($numberTaken) {
            throw ValidationException::withMessages([
                'service_no' => __('Service ID already exists for this position.'),
            ]);
        }

    }

    private function feedbackExistsFor(PositionService $record): bool
    {
        return EmployeeServiceFeedback::query()
            ->where('position_service_id', $record->getKey())
            ->exists();
    }

    /** @return Collection<int, Organization> */
    private function scopedOrganizations(mixed $user, bool $unrestricted): mixed
    {
        return Organization::query()
            ->where('status', 'active')
            ->when(! $unrestricted, fn ($query) => $query->whereIn(
                'id',
                $this->scope->accessibleOrganizationIds($user)->all(),
            ))
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_am']);
    }

    /** @return array<string, mixed> */
    private function present(PositionService $record): array
    {
        return [
            'id' => $record->id,
            'service_no' => $record->service_no,
            'is_active' => $record->is_active,
            'is_performance_evaluation_enabled' => $record->is_performance_evaluation_enabled,
            'sort_order' => $record->sort_order,
            'position' => $record->position === null ? null : [
                'id' => $record->position->id,
                'code' => $record->position->job_position_code,
                'title_en' => $record->position->title_en,
                'title_am' => $record->position->title_am,
            ],
            'name_en' => $record->name_en,
            'name_am' => $record->name_am,
            'description' => $record->description,
            'organization' => $record->organization === null ? null : [
                'id' => $record->organization->id,
                'name_en' => $record->organization->name_en,
                'name_am' => $record->organization->name_am,
            ],
        ];
    }
}
