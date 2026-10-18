<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\OrganizationUnit;
use App\Models\SituationRoomAction;
use App\Models\User;
use App\Rules\RichTextContent;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\ServiceFeedback\PublicFeedbackVisibility;
use App\Services\SituationRoom\SituationRoomActionService;
use App\Services\SituationRoom\SituationRoomMetrics as M;
use App\Services\SituationRoom\SituationRoomScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Situation Room Action Register: management follow-ups per institution.
 * Seeing the list takes situation_room.view; recording or changing an action
 * takes situation_room.manage_actions and an institution in the user's scope.
 */
class SituationRoomActionController extends Controller
{
    public function __construct(
        private readonly SituationRoomActionService $actions,
        private readonly OrganizationScopeService $scopes,
        private readonly PublicFeedbackVisibility $visibility,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->user(M::PERMISSION_VIEW);
        $scope = $this->scope($user, $request->query('organization'));
        $status = in_array($request->query('status'), SituationRoomAction::STATUSES, true) ? (string) $request->query('status') : null;
        $open = $request->query('status') === 'open_all' || $status === null;

        $query = SituationRoomAction::query()
            ->with(['organization:id,code,name_en,name_am', 'ownerUser:id,name', 'ownerUnit:id,name_en,name_am', 'creator:id,name', 'events.actor:id,name'])
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->when($status === null && $open, fn ($q) => $q->whereNotIn('status', SituationRoomAction::CLOSED_STATUSES));
        $scope->apply($query, 'situation_room_actions.organization_id');

        $actions = $query->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')->orderBy('due_date')->latest('created_at')->paginate(25)->withQueryString();

        return Inertia::render('SituationRoom/Actions', [
            'scope' => [
                'node' => $scope->node ? ['id' => (string) $scope->node->getKey(), 'code' => $scope->node->code, 'name_en' => $scope->node->name_en, 'name_am' => $scope->node->name_am] : null,
                'breadcrumb' => $scope->breadcrumb(),
            ],
            'filters' => ['status' => $status],
            'actions' => $actions->through(fn (SituationRoomAction $action): array => $this->present($action)),
            'options' => [
                'statuses' => SituationRoomAction::STATUSES,
                'priorities' => SituationRoomAction::PRIORITIES,
                'sourceTypes' => SituationRoomAction::SOURCE_TYPES,
            ],
            'can' => ['manage' => $user->can(M::PERMISSION_ACTIONS)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user(M::PERMISSION_ACTIONS);
        $data = $request->validate([
            'organization_id' => ['required', 'uuid', Rule::exists('organizations', 'id')],
            'source_type' => ['nullable', Rule::in(SituationRoomAction::SOURCE_TYPES)],
            'source_reference' => ['nullable', 'string', 'max:100'],
            'title' => ['required', 'string', 'max:200'],
            'action_text' => ['nullable', new RichTextContent(maxLength: 5000)],
            'owner_user_id' => ['nullable', 'integer'],
            'owner_organization_unit_id' => ['nullable', 'uuid'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'priority' => ['nullable', Rule::in(SituationRoomAction::PRIORITIES)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        abort_unless($this->scopes->isUnrestricted($user) || $this->scopes->canAccess($user, $data['organization_id']), 403);

        $this->actions->create($user, $data, $request);

        return back()->with('success', __('situation-room.action_created'));
    }

    public function update(Request $request, SituationRoomAction $action): RedirectResponse
    {
        $user = $this->user(M::PERMISSION_ACTIONS);
        abort_unless($this->scopes->isUnrestricted($user) || $this->scopes->canAccess($user, (string) $action->organization_id), 403);

        $data = $request->validate([
            'status' => ['sometimes', Rule::in(SituationRoomAction::STATUSES)],
            'owner_user_id' => ['sometimes', 'nullable', 'integer'],
            'owner_organization_unit_id' => ['sometimes', 'nullable', 'uuid'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'priority' => ['sometimes', Rule::in(SituationRoomAction::PRIORITIES)],
            'title' => ['sometimes', 'string', 'max:200'],
            'action_text' => ['sometimes', 'nullable', new RichTextContent(maxLength: 5000)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->actions->update($user, $action, $data, $request);

        return back()->with('success', __('situation-room.action_updated'));
    }

    /** People and units who could own an action for one institution. */
    public function owners(Request $request): JsonResponse
    {
        $user = $this->user(M::PERMISSION_ACTIONS);
        $organizationId = (string) $request->query('organization', '');
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $organizationId) === 1, 404);
        abort_unless($this->scopes->isUnrestricted($user) || $this->scopes->canAccess($user, $organizationId), 403);
        $term = trim((string) $request->query('q', ''));

        $units = OrganizationUnit::query()->where('organization_id', $organizationId)
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->whereRaw('LOWER(name_en) LIKE ?', ['%'.mb_strtolower($term).'%'])->orWhere('name_am', 'like', '%'.$term.'%')))
            ->orderBy('name_en')->limit(20)->get(['id', 'name_en', 'name_am'])
            ->map(fn ($u): array => ['id' => (string) $u->id, 'name_en' => $u->name_en, 'name_am' => $u->name_am])->all();

        // Accounts whose home institution is this one; the server re-checks scope on save.
        $users = User::query()->where('default_organization_id', $organizationId)->where('status', 'active')
            ->when($term !== '', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($term).'%']))
            ->orderBy('name')->limit(20)->get(['id', 'name'])
            ->map(fn (User $u): array => ['id' => (int) $u->id, 'name' => $u->name])->all();

        return response()->json(['users' => $users, 'units' => $units]);
    }

    /** @return array<string, mixed> */
    private function present(SituationRoomAction $action): array
    {
        return [
            'id' => (string) $action->id,
            'organization' => $action->organization ? ['id' => (string) $action->organization->id, 'code' => $action->organization->code, 'name_en' => $action->organization->name_en, 'name_am' => $action->organization->name_am] : null,
            'source_type' => $action->source_type,
            'source_reference' => $action->source_reference,
            'title' => $action->title,
            'action_text' => $action->action_text,
            'owner_user' => $action->ownerUser ? ['id' => (int) $action->ownerUser->id, 'name' => $action->ownerUser->name] : null,
            'owner_unit' => $action->ownerUnit ? ['id' => (string) $action->ownerUnit->id, 'name_en' => $action->ownerUnit->name_en, 'name_am' => $action->ownerUnit->name_am] : null,
            'due_date' => $action->due_date?->toDateString(),
            'priority' => $action->priority,
            'status' => $action->status,
            'created_by' => $action->creator?->name,
            'created_at' => $action->created_at?->toIso8601String(),
            'closed_at' => $action->closed_at?->toIso8601String(),
            'events' => $action->events->map(fn ($event): array => [
                'id' => (string) $event->id,
                'event' => $event->event,
                'from' => $event->from_value,
                'to' => $event->to_value,
                'note' => $event->note,
                'actor' => $event->actor?->name,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
            ])->all(),
        ];
    }

    private function user(string $permission): User
    {
        /** @var User $user */
        $user = Auth::user();
        abort_unless($user->can(M::PERMISSION_VIEW) && $user->can($permission), 403);

        return $user;
    }

    private function scope(User $user, mixed $organizationId): SituationRoomScope
    {
        $id = is_string($organizationId) && preg_match('/^[0-9a-f-]{36}$/i', $organizationId) === 1 ? $organizationId : null;

        try {
            return SituationRoomScope::for($user, $id, $this->scopes, $this->visibility);
        } catch (AuthorizationException) {
            abort(403);
        }
    }
}
