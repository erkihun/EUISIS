<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Intelligence;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\IntelligenceAlert;
use App\Models\User;
use App\Services\Intelligence\IntelligenceLabels;
use App\Services\Intelligence\IntelligencePermissions;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Alerts (docs/intelligence-alerts.md). Visibility follows the two domains:
 * technical alerts need an incidents or system-health grant; business alerts
 * are city-wide figures and need a business grant plus an unrestricted scope.
 * Acknowledging and resolving are audited; the engine never does either.
 */
final class AlertController extends Controller
{
    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly WriteAuditLogAction $audit,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([...IntelligenceAlert::STATUSES, 'active'])],
            'domain' => ['nullable', Rule::in(IntelligenceAlert::DOMAINS)],
        ]);
        $status = $filters['status'] ?? 'active';
        $visible = $this->visibleDomains($user);

        $query = IntelligenceAlert::query()->whereIn('domain', $visible)->with(['acknowledger:id,name', 'resolver:id,name'])
            ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 when 'medium' then 2 when 'low' then 3 else 4 end")->orderByDesc('last_detected_at');
        $status === 'active' ? $query->whereIn('status', IntelligenceAlert::ACTIVE_STATUSES) : $query->where('status', $status);
        if (! empty($filters['domain'])) {
            $query->where('domain', $filters['domain']);
        }

        $counts = IntelligenceAlert::query()->whereIn('domain', $visible)->whereIn('status', IntelligenceAlert::ACTIVE_STATUSES)->selectRaw('domain, count(*) as c')->groupBy('domain')->pluck('c', 'domain');

        return Inertia::render('Intelligence/Alerts/Index', [
            'labels' => IntelligenceLabels::dictionary(),
            'filters' => ['status' => $status, 'domain' => $filters['domain'] ?? ''],
            'options' => ['statuses' => IntelligenceAlert::STATUSES, 'domains' => $visible],
            'counts' => ['business' => (int) ($counts['business'] ?? 0), 'technical' => (int) ($counts['technical'] ?? 0)],
            'alerts' => $query->paginate(25)->withQueryString()->through(fn (IntelligenceAlert $a): array => [
                'id' => $a->id, 'type' => $a->type, 'domain' => $a->domain, 'severity' => $a->severity, 'status' => $a->status, 'title' => $a->title,
                'params' => $a->params ?? [], 'evidence' => $a->evidence ?? [], 'recommendation' => $a->recommendation, 'href' => $a->href, 'area' => $a->area,
                'occurrences' => $a->occurrences, 'first_detected_at' => $a->first_detected_at->toIso8601String(), 'last_detected_at' => $a->last_detected_at->toIso8601String(),
                'acknowledged_by' => $a->acknowledger?->name, 'acknowledged_at' => $a->acknowledged_at?->toIso8601String(), 'resolved_by' => $a->resolver?->name, 'resolved_at' => $a->resolved_at?->toIso8601String(),
                'can_manage' => $this->canManage($user, $a->domain),
            ]),
        ]);
    }

    public function update(Request $request, IntelligenceAlert $alert): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(in_array($alert->domain, $this->visibleDomains($user), true) && $this->canManage($user, $alert->domain), 403);
        $data = $request->validate(['action' => ['required', Rule::in(['acknowledge', 'resolve', 'reopen'])]]);
        $before = $alert->only(['status']);

        match ($data['action']) {
            'acknowledge' => $alert->forceFill(['status' => 'acknowledged', 'acknowledged_by' => $user->id, 'acknowledged_at' => now()])->save(),
            'resolve' => $alert->forceFill(['status' => 'resolved', 'resolved_by' => $user->id, 'resolved_at' => now(), 'cooldown_until' => now()->addHours(24)])->save(),
            'reopen' => $alert->forceFill(['status' => 'open', 'resolved_by' => null, 'resolved_at' => null, 'cooldown_until' => null, 'clear_runs' => 0])->save(),
        };

        $this->audit->execute(AuditEventType::IntelligenceAlertUpdated, $user, $alert, oldValues: $before, newValues: $alert->only(['status']) + ['action' => $data['action']], request: $request);

        return back()->with('flash', ['message' => __('intelligence.flash.alert_updated'), 'type' => 'success']);
    }

    /** @return list<string> */
    private function visibleDomains(User $user): array
    {
        $domains = [];
        if ($user->can(IntelligencePermissions::INCIDENTS) || $user->can(IntelligencePermissions::SYSTEM_HEALTH)) {
            $domains[] = 'technical';
        }
        $business = array_filter(IntelligencePermissions::business(), fn (string $p): bool => $p !== IntelligencePermissions::KNOWLEDGE && $user->can($p));
        if ($business !== [] && $this->scope->isUnrestricted($user)) {
            $domains[] = 'business';
        }

        return $domains;
    }

    private function canManage(User $user, string $domain): bool
    {
        return $domain === 'technical' ? $user->can(IntelligencePermissions::INCIDENTS_MANAGE) : $user->can(IntelligencePermissions::EXECUTIVE);
    }
}
