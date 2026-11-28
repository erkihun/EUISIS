<?php

declare(strict_types=1);

namespace Modules\Leave\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Leave\Application\Services\LeaveAccess;
use Modules\Leave\Application\Services\LeaveCalendar;
use Modules\Leave\Application\Services\LeavePresenter;
use Modules\Leave\Contracts\Data\LeaveStatus;
use Modules\Leave\Domain\Models\LeaveRequest;
use Modules\Leave\Domain\Models\LeaveType;
use Modules\Leave\Http\Support\LeavePopups;

/**
 * Staff pages: the institution's leave register (HR), the supervisor's
 * inbox and one request. Nobody enters a request for an employee here: an
 * employee asks for leave signed in with their own account (My Portal).
 */
class RequestController extends Controller
{
    private const RELATIONS = ['employee', 'leaveType', 'organization:id,name_en,name_am', 'organizationUnit:id,name_en,name_am'];

    public function __construct(
        private readonly LeaveAccess $access,
        private readonly LeavePresenter $presenter,
        private readonly LeaveCalendar $calendar,
        private readonly LeavePopups $popups,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('leave.view_org'), 403);
        $filters = $this->filters($request);

        $query = $this->access->constrainToScope(LeaveRequest::query()->with(self::RELATIONS), $user);
        $this->applyFilters($query, $filters);

        return Inertia::render('modules/leave/Requests', [
            'leaveRequests' => $query->orderByDesc('submitted_at')->orderByDesc('id')->paginate(20)->withQueryString()
                ->through(fn (LeaveRequest $row): array => $this->presenter->row($row)),
            'filters' => $filters,
            'leaveTypes' => $this->typeOptions(),
            'waiting' => $this->access->constrainToScope(LeaveRequest::query(), $user)->where('status', LeaveStatus::PendingHr->value)->count(),
            ...$this->popupProps($request),
        ]);
    }

    /** Requests routed to the signed-in supervisor. */
    public function approvals(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('leave.view_team'), 403);
        $status = in_array($request->query('status'), ['pending', 'all'], true) ? (string) $request->query('status') : 'pending';

        $query = LeaveRequest::query()->with(self::RELATIONS)->where('supervisor_user_id', $user->getKey())
            ->when($status === 'pending', fn (Builder $q) => $q->where('status', LeaveStatus::PendingSupervisor->value));

        return Inertia::render('modules/leave/Approvals', [
            'leaveRequests' => $query->orderByDesc('submitted_at')->orderByDesc('id')->paginate(20)->withQueryString()
                ->through(fn (LeaveRequest $row): array => $this->presenter->row($row)),
            'filters' => ['status' => $status],
            ...$this->popupProps($request),
        ]);
    }

    public function show(Request $request, LeaveRequest $leaveRequest): Response
    {
        $user = $request->user();
        abort_unless($this->access->canView($user, $leaveRequest), 403);

        return Inertia::render('modules/leave/RequestShow', [
            'leaveRequest' => $this->presenter->detail($leaveRequest, $user),
            'viewContext' => 'staff',
            'leaveBalance' => $this->popups->balanceOf($leaveRequest),
            'today' => $this->calendar->today(),
        ]);
    }

    /**
     * The popup every list of requests opens: one request.
     *
     * @return array<string, mixed>
     */
    private function popupProps(Request $request): array
    {
        return [
            'leaveOpenRequest' => $this->popups->request($request),
            'today' => $this->calendar->today(),
        ];
    }

    /** @return array{status: string, type: string, search: string, from: string, to: string} */
    private function filters(Request $request): array
    {
        $date = static fn (mixed $value): string => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
        $status = (string) $request->query('status', '');

        return [
            'status' => in_array($status, array_column(LeaveStatus::cases(), 'value'), true) ? $status : '',
            'type' => is_string($request->query('type')) ? (string) $request->query('type') : '',
            'search' => is_string($request->query('search')) ? trim((string) $request->query('search')) : '',
            'from' => $date($request->query('from')),
            'to' => $date($request->query('to')),
        ];
    }

    /** @param array{status: string, type: string, search: string, from: string, to: string} $filters */
    private function applyFilters(Builder $query, array $filters): void
    {
        $query->when($filters['status'] !== '', fn (Builder $q) => $q->where('status', $filters['status']))
            ->when($filters['type'] !== '', fn (Builder $q) => $q->where('leave_type_id', $filters['type']))
            // Any leave that touches the period.
            ->when($filters['from'] !== '', fn (Builder $q) => $q->whereDate('ends_on', '>=', $filters['from']))
            ->when($filters['to'] !== '', fn (Builder $q) => $q->whereDate('starts_on', '<=', $filters['to']))
            ->when($filters['search'] !== '', function (Builder $q) use ($filters): void {
                $like = '%'.addcslashes($filters['search'], '%_\\').'%';
                $q->where(fn (Builder $inner) => $inner->where('reference_number', 'like', $like)
                    ->orWhereHas('employee', fn (Builder $e) => $e->where('employee_number', 'like', $like)->orWhere('full_name', 'like', $like)->orWhere('name_en', 'like', $like)));
            });
    }

    /** @return list<array{id: string, name_en: string, name_am: string}> */
    private function typeOptions(): array
    {
        return LeaveType::query()->orderBy('sort_order')->get(['id', 'name_en', 'name_am'])
            ->map(fn (LeaveType $type): array => ['id' => $type->id, 'name_en' => $type->name_en, 'name_am' => $type->name_am])->all();
    }
}
