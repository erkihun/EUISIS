<?php

declare(strict_types=1);

namespace App\Http\Controllers\Communication;

use App\Actions\Communication\DecideInvestigationAccessAction;
use App\Actions\Communication\RequestInvestigationAccessAction;
use App\Actions\Communication\RevokeInvestigationAccessAction;
use App\Enums\Communication\AccessRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\StoreAccessRequest;
use App\Models\Communication\CommunicationAccessRequest;
use App\Models\Communication\Conversation;
use App\Models\User;
use App\Services\Communication\CommunicationSettings;
use App\Services\Communication\InvestigationViewer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Break-glass investigation access: investigators request, someone else
 * approves for a limited time, and the investigator reads (read-only, every
 * page audited). Approvers and auditors see the requests, never the content.
 */
class InvestigationController extends Controller
{
    public function index(Request $request, CommunicationSettings $settings): Response
    {
        $user = $request->user();
        abort_unless(Gate::forUser($user)->allows('viewAny', CommunicationAccessRequest::class), 403);
        $sees = $user->checkPermissionTo('communication.break_glass.approve') || $user->checkPermissionTo('communication.audit');
        $rows = CommunicationAccessRequest::query()->with(['requester:id,name', 'decider:id,name', 'targetUser:id,name'])
            ->when(! $sees, fn ($q) => $q->where('requested_by', $user->getKey()))
            ->orderByRaw("case when status = 'REQUESTED' then 0 else 1 end")->latest('created_at')->limit(200)->get();

        return Inertia::render('Communication/Investigations/Index', [
            'requests' => $rows->map(fn ($r) => $this->present($r, $user))->values(),
            'can' => ['request' => $user->checkPermissionTo('communication.break_glass.request')],
            'maxHours' => $settings->breakGlassMaxHours(),
        ]);
    }

    public function store(StoreAccessRequest $request, RequestInvestigationAccessAction $create): RedirectResponse
    {
        $created = $create->execute($request->user(), $request->validated());

        return redirect()->route('communication.investigations.show', $created)->with('success', __('communication.flash.access_requested'));
    }

    public function show(Request $request, CommunicationAccessRequest $investigation, InvestigationViewer $viewer): Response
    {
        $user = $request->user();
        abort_unless(Gate::forUser($user)->allows('view', $investigation), 404);
        $investigation->load(['requester:id,name', 'decider:id,name', 'targetUser:id,name', 'conversation.channel']);
        $own = (int) $investigation->requested_by === (int) $user->getKey();

        return Inertia::render('Communication/Investigations/Show', [
            'request' => $this->present($investigation, $user),
            'conversations' => $own && $investigation->isInForce() ? $viewer->conversations($user, $investigation, app()->getLocale()) : null,
        ]);
    }

    public function messages(Request $request, CommunicationAccessRequest $investigation, Conversation $conversation, InvestigationViewer $viewer): JsonResponse
    {
        try {
            return response()->json($viewer->messages($request->user(), $investigation, $conversation, $request->string('before')->toString() ?: null));
        } catch (AuthorizationException) {
            abort(404);
        }
    }

    public function decide(Request $request, CommunicationAccessRequest $investigation, DecideInvestigationAccessAction $decide): RedirectResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('view', $investigation), 404);
        $data = $request->validate([
            'approve' => ['required', 'boolean'],
            'note' => ['required', 'string', 'min:10', 'max:2000'],
            'hours' => ['nullable', 'integer', 'min:1', 'max:72'],
        ]);
        $decide->execute($request->user(), $investigation, (bool) $data['approve'], $data['note'], isset($data['hours']) ? (int) $data['hours'] : null);

        return back()->with('success', __($data['approve'] ? 'communication.flash.access_approved' : 'communication.flash.access_rejected'));
    }

    public function revoke(Request $request, CommunicationAccessRequest $investigation, RevokeInvestigationAccessAction $revoke): RedirectResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('view', $investigation), 404);
        $revoke->execute($request->user(), $investigation, $request->string('reason')->limit(1000)->toString() ?: null);

        return back()->with('success', __('communication.flash.access_revoked'));
    }

    /** People an investigator may name (search only; investigators work city-wide). */
    public function people(Request $request): JsonResponse
    {
        abort_unless($request->user()->checkPermissionTo('communication.break_glass.request'), 403);
        $term = trim($request->string('q')->toString());
        if (mb_strlen($term) < 2) {
            return response()->json(['data' => []]);
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';
        $rows = User::query()->leftJoin('employees', 'employees.id', '=', 'users.employee_id')
            ->where(fn ($q) => $q->where('users.name', ci_like_operator(), $like)->orWhere('employees.employee_number', ci_like_operator(), $like)
                ->orWhere('employees.full_name', ci_like_operator(), $like))
            ->whereKeyNot($request->user()->getKey())
            ->orderBy('users.name')->limit(20)->get(['users.id', 'users.name', 'employees.employee_number']);

        return response()->json(['data' => $rows->map(fn ($u) => ['id' => (int) $u->id, 'name' => $u->name, 'hint' => $u->employee_number])->values()]);
    }

    /** @return array<string, mixed> */
    private function present(CommunicationAccessRequest $r, User $viewer): array
    {
        $gate = Gate::forUser($viewer);

        return [
            'id' => $r->getKey(),
            'status' => $r->status->value,
            'in_force' => $r->isInForce(),
            'scope_type' => $r->scope_type->value,
            'conversation_id' => $r->conversation_id,
            'target' => $r->targetUser?->name,
            'reason' => $r->reason,
            'case_reference' => $r->case_reference,
            'requested_hours' => $r->requested_hours,
            'requester' => $r->requester?->name,
            'decider' => $r->decider?->name,
            'decision_note' => $r->decision_note,
            'created_at' => $r->created_at?->toIso8601String(),
            'decided_at' => $r->decided_at?->toIso8601String(),
            'starts_at' => $r->starts_at?->toIso8601String(),
            'expires_at' => $r->expires_at?->toIso8601String(),
            'revoked_at' => $r->revoked_at?->toIso8601String(),
            'mine' => (int) $r->requested_by === (int) $viewer->getKey(),
            'can' => [
                'decide' => $gate->allows('decide', $r),
                'revoke' => $gate->allows('revoke', $r) && $r->status !== AccessRequestStatus::Expired,
            ],
        ];
    }
}
