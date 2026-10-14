<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\SituationRoom;

use App\Actions\SituationRoom\RecordSituationActionAction;
use App\Actions\SituationRoom\UpdateSituationActionAction;
use App\Enums\SituationActionPriority;
use App\Enums\SituationActionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SituationRoom\SaveSituationActionRequest;
use App\Models\Organization;
use App\Models\SituationAction;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\SituationRoom\SituationCategory;
use App\Services\SituationRoom\SituationLabels;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Executive Action Register. Scoped like everything else in the room:
 * a user lists, opens and changes only actions of organizations in their
 * scope, and an action outside it is a 404, not a 403, so its existence is
 * not disclosed. Create and edit are pages of their own.
 */
final class SituationActionController extends Controller
{
    public function __construct(private readonly OrganizationScopeService $scope) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', SituationAction::class);
        /** @var User $user */
        $user = $request->user();
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(SituationActionStatus::values())],
            'category' => ['nullable', Rule::in(array_column(SituationCategory::cases(), 'value'))],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $actions = $this->scope->applyOrganizationScope(SituationAction::query(), $user)
            ->with('organization:id,name_en,name_am')
            ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('status', $status))
            ->when($filters['category'] ?? null, fn ($q, string $category) => $q->where('category', $category))
            ->when($filters['search'] ?? null, fn ($q, string $search) => $q->where(fn ($w) => $w
                ->where('title', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('reference_number', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->orderByRaw("CASE WHEN status IN ('open', 'in_progress') THEN 0 ELSE 1 END")
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (SituationAction $a): array => $this->row($a));

        return Inertia::render('SituationRoom/Actions/Index', [
            'labels' => SituationLabels::dictionary(),
            'actions' => $actions,
            'filters' => $filters,
            'options' => $this->options($user),
            'can' => ['manage' => $user->can('create', SituationAction::class)],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', SituationAction::class);
        /** @var User $user */
        $user = $request->user();
        $prefill = $request->validate([
            'organization_id' => ['nullable', 'uuid'],
            'category' => ['nullable', Rule::in(array_column(SituationCategory::cases(), 'value'))],
            'panel_key' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9_]+$/'],
        ]);

        if (isset($prefill['organization_id']) && ! $this->scope->canAccessOrganization($user, $prefill['organization_id'])) {
            unset($prefill['organization_id']);
        }

        return Inertia::render('SituationRoom/Actions/Form', [
            'labels' => SituationLabels::dictionary(),
            'action' => null,
            'prefill' => $prefill,
            'options' => $this->options($user),
        ]);
    }

    public function store(SaveSituationActionRequest $request, RecordSituationActionAction $record): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $action = $record->execute($request->validated(), $user, $request);

        return redirect()->route('situation-room.actions.index')->with('success', SituationLabels::text('actions.created', app()->getLocale()).' '.$action->reference_number);
    }

    public function edit(Request $request, SituationAction $action): Response
    {
        $this->visible($request, $action);
        $this->authorize('update', $action);

        return Inertia::render('SituationRoom/Actions/Form', [
            'labels' => SituationLabels::dictionary(),
            'action' => $this->row($action->load('organization:id,name_en,name_am', 'creator:id,name', 'updater:id,name')) + [
                'description' => $action->description,
                'outcome_note' => $action->outcome_note,
                'panel_key' => $action->panel_key,
                'created_by' => $action->creator?->name,
                'updated_by' => $action->updater?->name,
            ],
            'prefill' => [],
            'options' => $this->options($request->user()),
        ]);
    }

    public function update(SaveSituationActionRequest $request, SituationAction $action, UpdateSituationActionAction $update): RedirectResponse
    {
        $this->visible($request, $action);
        /** @var User $user */
        $user = $request->user();
        $update->execute($action, $request->validated(), $user, $request);

        return redirect()->route('situation-room.actions.index')->with('success', SituationLabels::text('actions.updated', app()->getLocale()));
    }

    /** An action outside the viewer's scope does not exist for them. */
    private function visible(Request $request, SituationAction $action): void
    {
        abort_unless($this->scope->canAccessOrganization($request->user(), $action->organization_id), 404);
    }

    /** @return array<string, mixed> */
    private function row(SituationAction $action): array
    {
        return [
            'id' => $action->id,
            'reference_number' => $action->reference_number,
            'title' => $action->title,
            'category' => $action->category,
            'organization_id' => $action->organization_id,
            'organization' => ['en' => $action->organization?->name_en, 'am' => $action->organization?->name_am],
            'priority' => $action->priority->value,
            'status' => $action->status->value,
            'responsible_office' => $action->responsible_office,
            'due_date' => $action->due_date?->toDateString(),
            'completed_at' => $action->completed_at?->toIso8601String(),
            'overdue' => $action->due_date !== null && ! $action->status->isFinal() && $action->due_date->lt(today()),
        ];
    }

    /** @return array<string, mixed> */
    private function options(User $user): array
    {
        $organizations = $this->scope->applyOrganizationScope(Organization::query(), $user, 'id')
            ->orderBy('name_en')
            ->limit(2000)
            ->get(['id', 'name_en', 'name_am'])
            ->map(fn (Organization $o): array => ['id' => (string) $o->id, 'label' => ['en' => $o->name_en, 'am' => $o->name_am]])
            ->all();

        return [
            'organizations' => $organizations,
            'categories' => array_column(SituationCategory::cases(), 'value'),
            'priorities' => SituationActionPriority::values(),
            'statuses' => SituationActionStatus::values(),
        ];
    }
}
