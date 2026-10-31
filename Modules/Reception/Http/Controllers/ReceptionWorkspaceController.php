<?php

declare(strict_types=1);

namespace Modules\Reception\Http\Controllers;

use App\Enums\OrganizationDesignStatus;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Service;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Reception\Application\Services\ReceptionAccess;
use Modules\Reception\Contracts\Data\ReceptionPermissions;
use Modules\Reception\Domain\Models\ReceptionAppointment;
use Modules\Reception\Domain\Models\ReceptionCounter;
use Modules\Reception\Domain\Models\ReceptionDesk;
use Modules\Reception\Domain\Models\ReceptionQueueTicket;
use Modules\Reception\Domain\Models\ReceptionReferral;
use Modules\Reception\Domain\Models\ReceptionVisit;
use Modules\Reception\Domain\Models\ReceptionVisitor;

final class ReceptionWorkspaceController extends Controller
{
    public function __invoke(Request $request, string $section, ReceptionAccess $access, OrganizationScopeService $organizationScope): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->can(ReceptionPermissions::VIEW), 403);

        $desks = $access->scope(ReceptionDesk::query(), $user)->with(['staff:id,name,email'])->withCount(['counters', 'staff'])->orderBy('name_en')->get();
        $deskIds = $desks->pluck('id');
        $allowedOrganizationIds = $organizationScope->allowedOrganizationIds($user);
        $visitQuery = $access->scope(ReceptionVisit::query(), $user)->with(['visitor:id,name,phone,category', 'desk:id,code,name_en,name_am', 'service:id,name_en,name_am', 'queueTicket:id,visit_id,display_number,status,reception_counter_id'])->latest('checked_in_at');
        $today = now()->toDateString();
        $todayVisits = (clone $visitQuery)->whereDate('checked_in_at', $today)->get();

        $waitingDurations = $todayVisits->filter(fn (ReceptionVisit $v): bool => $v->service_started_at !== null)->map(fn (ReceptionVisit $v): int => $v->checked_in_at->diffInSeconds($v->service_started_at));
        $serviceDurations = $todayVisits->filter(fn (ReceptionVisit $v): bool => $v->service_started_at !== null && $v->completed_at !== null)->map(fn (ReceptionVisit $v): int => $v->service_started_at->diffInSeconds($v->completed_at));

        $payload = [
            'section' => $section,
            'stats' => [
                'received' => $todayVisits->count(), 'waiting' => $todayVisits->where('status', 'waiting')->count(),
                'in_service' => $todayVisits->where('status', 'in_service')->count(), 'completed' => $todayVisits->where('status', 'completed')->count(),
                'referred' => $todayVisits->where('status', 'referred')->count(),
                'cancelled' => $todayVisits->whereIn('status', ['cancelled', 'no_show'])->count(),
                'average_wait_minutes' => $waitingDurations->isEmpty() ? 0 : round($waitingDurations->average() / 60, 1),
                'average_service_minutes' => $serviceDurations->isEmpty() ? 0 : round($serviceDurations->average() / 60, 1),
                'active_counters' => ReceptionCounter::query()->whereIn('reception_desk_id', $deskIds)->where('status', 'open')->count(),
                'appointments' => ReceptionAppointment::query()->whereIn('reception_desk_id', $deskIds)->whereDate('scheduled_at', $today)->count(),
            ],
            'desks' => $desks->map(fn (ReceptionDesk $d): array => ['id' => $d->id, 'organization_id' => $d->organization_id, 'code' => $d->code, 'name_en' => $d->name_en, 'name_am' => $d->name_am, 'location_en' => $d->location_en, 'location_am' => $d->location_am, 'is_active' => $d->is_active, 'counters_count' => $d->counters_count, 'staff_count' => $d->staff_count, 'staff' => $d->staff->map(fn (User $s): array => ['id' => $s->id, 'name' => $s->name, 'email' => $s->email, 'role' => $s->pivot->role, 'is_active' => (bool) $s->pivot->is_active])]),
            'visits' => $this->visits($visitQuery, $section, $request),
            'queue' => ReceptionQueueTicket::query()->whereIn('reception_desk_id', $deskIds)->with(['visit.visitor:id,name', 'counter:id,code,name_en,name_am'])->whereDate('queue_date', $today)->whereIn('status', ['waiting', 'called', 'in_service'])->orderByRaw("CASE WHEN priority = 'approved_priority' THEN 0 ELSE 1 END")->orderBy('queue_number')->get(),
            'counters' => ReceptionCounter::query()->whereIn('reception_desk_id', $deskIds)->with('desk:id,code,name_en,name_am')->orderBy('code')->get(),
            'appointments' => ReceptionAppointment::query()->whereIn('reception_desk_id', $deskIds)->with('visitor:id,name')->whereBetween('scheduled_at', [now()->startOfDay(), now()->addDays(30)->endOfDay()])->orderBy('scheduled_at')->limit(200)->get(),
            'referrals' => ReceptionReferral::query()->where(function (Builder $q) use ($allowedOrganizationIds): void {
                $q->whereIn('from_organization_id', $allowedOrganizationIds)->orWhereIn('to_organization_id', $allowedOrganizationIds);
            })->with('visit.visitor:id,name')->latest('referred_at')->limit(200)->get(),
            'organizations' => $access->scope(Organization::query(), $user, 'id')->where('status', 'active')->orderBy('name_en')->get(['id', 'name_en', 'name_am']),
            'units' => OrganizationUnit::query()->whereIn('organization_id', $desks->pluck('organization_id'))->where('status', 'active')->orderBy('name_en')->limit(2000)->get(['id', 'organization_id', 'name_en', 'name_am']),
            'services' => Service::query()->whereIn('organization_id', $desks->pluck('organization_id'))->where('status', OrganizationDesignStatus::Published->value)->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', today()))->orderBy('name_en')->limit(2000)->get(['id', 'organization_id', 'owner_organization_unit_id', 'name_en', 'name_am', 'description_en', 'description_am', 'delivery_channel']),
            'users' => $organizationScope->applyUserScope(User::query(), $user)->where('status', 'active')->orderBy('name')->limit(1000)->get(['id', 'name', 'email']),
            'visitors' => ReceptionVisitor::query()->whereHas('visits', fn (Builder $q) => $access->scope($q, $user))->orderBy('name')->limit(1000)->get(['id', 'name', 'category']),
            'can' => collect(ReceptionPermissions::ALL)->mapWithKeys(fn (string $permission): array => [$permission => $user->can($permission)])->all(),
        ];

        return Inertia::render('Reception/Workspace', $payload);
    }

    private function visits(Builder $query, string $section, Request $request): array
    {
        if (! in_array($section, ['dashboard', 'register', 'check-in', 'today', 'history', 'reports', 'referrals'], true)) {
            return [];
        }
        if ($section !== 'history') {
            $query->whereDate('checked_in_at', today());
        }
        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn (Builder $q) => $q->where('reference', 'like', "%{$search}%")->orWhereHas('visitor', fn (Builder $v) => $v->where('name', 'like', "%{$search}%")));
        }

        return $query->paginate(30)->through(fn (ReceptionVisit $v): array => [
            'id' => $v->id, 'reference' => $v->reference, 'visitor_name' => $v->visitor->name ?: __('reception.anonymous'),
            'visitor_category' => $v->visitor->category, 'phone_masked' => $this->maskPhone($v->visitor->phone),
            'desk' => $v->desk, 'service' => $v->service, 'status' => $v->status, 'outcome' => $v->outcome,
            'checked_in_at' => $v->checked_in_at?->toIso8601String(), 'service_started_at' => $v->service_started_at?->toIso8601String(),
            'completed_at' => $v->completed_at?->toIso8601String(), 'queue_ticket' => $v->queueTicket,
        ])->toArray();
    }

    private function maskPhone(?string $phone): ?string
    {
        if ($phone === null || strlen($phone) < 5) {
            return $phone === null ? null : '••••';
        }

        return substr($phone, 0, 3).str_repeat('•', max(2, strlen($phone) - 5)).substr($phone, -2);
    }
}
