<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Support;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SupportTicketEvent;
use App\Models\User;
use App\Services\Support\SupportTicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;

/**
 * Support tickets (docs/assistant/support-helpdesk.md). Reporters list, file
 * and read their own tickets; `support-tickets.view-all` sees every ticket;
 * `support-tickets.manage` changes status, assignment and adds notes. All
 * writes go through SupportTicketService, which sanitises, events and audits.
 */
final class SupportTicketController extends Controller
{
    public function __construct(private readonly SupportTicketService $tickets) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorize('viewAny', SupportTicket::class);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([...SupportTicket::STATUSES, 'active', 'all'])],
            'category' => ['nullable', Rule::in(SupportTicket::CATEGORIES)],
        ]);
        $status = $filters['status'] ?? 'active';
        $category = $filters['category'] ?? '';
        $viewAll = $user->can('viewAll', SupportTicket::class);

        $query = SupportTicket::query()->with(['reporter:id,name', 'assignee:id,name'])->orderByDesc('created_at');
        if (! $viewAll) {
            $query->where('reporter_user_id', $user->getKey());
        }
        if ($status === 'active') {
            $query->whereIn('status', SupportTicket::ACTIVE_STATUSES);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }
        if ($category !== '') {
            $query->where('category', $category);
        }

        return Inertia::render('Support/Tickets/Index', [
            'filters' => ['status' => $status, 'category' => $category],
            'options' => ['statuses' => SupportTicket::STATUSES, 'categories' => SupportTicket::CATEGORIES, 'priorities' => SupportTicket::PRIORITIES],
            'tickets' => $query->paginate(25)->withQueryString()->through(fn (SupportTicket $t): array => [
                'id' => $t->id, 'reference' => $t->reference, 'title' => $t->title, 'category' => $t->category, 'priority' => $t->priority,
                'status' => $t->status, 'module' => $t->module, 'assigned_team' => $t->assigned_team, 'assignee' => $t->assignee?->name,
                'reporter' => $viewAll ? $t->reporter?->name : null, 'created_at' => $t->created_at?->toIso8601String(), 'updated_at' => $t->updated_at?->toIso8601String(),
            ]),
            'can' => ['view_all' => $viewAll, 'manage' => $user->can(SupportTicket::PERMISSION_MANAGE)],
        ]);
    }

    /** The reporter's own direct creation from the page form: context comes from this request's route and User-Agent only. */
    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorize('create', SupportTicket::class);
        $data = $request->validate([
            'description' => ['required', 'string', 'max:'.SupportTicketService::DESCRIPTION_MAX],
            'category' => ['required', Rule::in(SupportTicket::CATEGORIES)],
            'priority' => ['nullable', Rule::in(SupportTicket::PRIORITIES)],
            'title' => ['nullable', 'string', 'max:190'],
        ]);

        $ticket = $this->tickets->create($user, [
            ...$data,
            'module' => 'support',
            'route_name' => $request->route()?->getName(),
            'path' => '/'.ltrim($request->path(), '/'),
            'user_agent' => $request->userAgent(),
            'locale' => app()->getLocale(),
        ]);

        return redirect()->route('support.tickets.show', $ticket)
            ->with('flash', ['message' => __('support.flash.created', ['reference' => $ticket->reference]), 'type' => 'success']);
    }

    public function show(Request $request, SupportTicket $ticket): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorize('view', $ticket);
        $ticket->load(['reporter:id,name', 'assignee:id,name', 'events.actor:id,name', 'incident:id,reference,severity,status']);
        $manage = $user->can('update', $ticket);

        return Inertia::render('Support/Tickets/Show', [
            'ticket' => [
                'id' => $ticket->id, 'reference' => $ticket->reference, 'title' => $ticket->title, 'description' => $ticket->description,
                'category' => $ticket->category, 'priority' => $ticket->priority, 'status' => $ticket->status,
                'module' => $ticket->module, 'route_name' => $ticket->route_name, 'path' => $ticket->path,
                'context' => $ticket->context ?? [], 'error_reference' => $ticket->error_reference,
                'incident' => $ticket->incident === null ? null : ['reference' => $ticket->incident->reference, 'severity' => $ticket->incident->severity, 'status' => $ticket->incident->status],
                'reporter' => $ticket->reporter?->name, 'assigned_team' => $ticket->assigned_team,
                'assigned_user_id' => $ticket->assigned_user_id, 'assignee' => $ticket->assignee?->name,
                'created_at' => $ticket->created_at?->toIso8601String(), 'updated_at' => $ticket->updated_at?->toIso8601String(),
                'resolved_at' => $ticket->resolved_at?->toIso8601String(), 'closed_at' => $ticket->closed_at?->toIso8601String(),
            ],
            'events' => $ticket->events->map(fn (SupportTicketEvent $e): array => [
                'id' => $e->id, 'event' => $e->event, 'from' => $e->from_value, 'to' => $e->to_value, 'note' => $e->note,
                'actor' => $e->actor?->name, 'occurred_at' => $e->occurred_at->toIso8601String(),
            ])->all(),
            'options' => ['statuses' => SupportTicket::STATUSES],
            'assignees' => $manage ? $this->assignees() : [],
            'can' => ['manage' => $manage],
        ]);
    }

    public function update(Request $request, SupportTicket $ticket): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorize('update', $ticket);
        $data = $request->validate([
            'status' => ['nullable', Rule::in(SupportTicket::STATUSES)],
            'assigned_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'assigned_team' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:'.SupportTicketService::DESCRIPTION_MAX],
        ]);
        $note = isset($data['note']) && trim($data['note']) !== '' ? $data['note'] : null;

        if ($request->has('assigned_user_id') || isset($data['assigned_team'])) {
            $assignee = isset($data['assigned_user_id']) ? User::query()->find($data['assigned_user_id']) : null;
            $this->tickets->assign($ticket, $assignee, $data['assigned_team'] ?? null, $user);
        }
        if (! empty($data['status']) && $data['status'] !== $ticket->status) {
            $this->tickets->transition($ticket, $data['status'], $user, $note);
            $note = null;
        }
        if ($note !== null) {
            $this->tickets->note($ticket, $note, $user);
        }

        return back()->with('flash', ['message' => __('support.flash.updated'), 'type' => 'success']);
    }

    /** People who hold the manage grant (directly or through a role), for the assignee select. @return list<array{id: int, name: string}> */
    private function assignees(): array
    {
        if (! Permission::query()->where('name', SupportTicket::PERMISSION_MANAGE)->where('guard_name', 'web')->exists()) {
            return [];
        }

        return User::permission(SupportTicket::PERMISSION_MANAGE)->orderBy('name')->limit(100)->get(['id', 'name'])
            ->map(fn (User $u): array => ['id' => (int) $u->getKey(), 'name' => (string) $u->name])->all();
    }
}
