<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Services;

use App\Backbone\Contracts\OrganizationDirectory;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Modules\Feedback\Contracts\Data\ServiceFeedbackStatus;
use Modules\Feedback\Contracts\PublicCaseDashboard;
use Modules\Feedback\Domain\Enums\PublicFeedbackHandlerType;
use Modules\Feedback\Domain\Models\PublicFeedbackAssignment;

/** The case-handling half of the Tip-off and complaint dashboards (ADR 0032). */
final class PublicCaseDashboardService implements PublicCaseDashboard
{
    public function __construct(
        private readonly PublicFeedbackVisibility $visibility,
        private readonly OrganizationScopeService $scope,
        private readonly OrganizationDirectory $organizations,
    ) {}

    public function scope(Builder $query, User $user): Builder
    {
        $query = $this->visibility->applyTargetScope($query, $user);
        if ($user->employee_id !== null) {
            $query->where(fn ($q) => $q->whereNull('employee_id')->orWhere('employee_id', '!=', $user->employee_id));
        }
        if (! $this->scope->isUnrestricted($user)) {
            $query->whereIn('organization_id', $this->scope->accessibleOrganizationIds($user)->all());
        }

        // Owner decision 2026-11-08: a handler sees only the cases in their hands where the institution has a Tip-off or Complaint Unit.
        return app(PublicCaseHandlingUnitService::class)->limitToHandled($query, $user);
    }

    public function build(Closure $query, User $user, array $statusOrder): array
    {
        $locale = app()->getLocale();
        $counts = $query()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n);
        $sum = fn (array $statuses): int => (int) collect($statuses)->sum(fn (string $s) => $counts[$s] ?? 0);
        $open = array_values(array_filter(ServiceFeedbackStatus::values(), fn (string $s) => ServiceFeedbackStatus::from($s)->isOpen()));

        // Last six months, counted in PHP so the query is the same on every database.
        $since = now()->subMonths(5)->startOfMonth();
        $months = collect(range(5, 0))->mapWithKeys(fn (int $back) => [now()->subMonths($back)->format('Y-m') => 0])->all();
        foreach ($query()->where('created_at', '>=', $since)->pluck('created_at') as $at) {
            $key = Carbon::parse($at)->format('Y-m');
            if (array_key_exists($key, $months)) {
                $months[$key]++;
            }
        }

        $byOrganization = $query()->whereNotNull('organization_id')->selectRaw('organization_id, COUNT(*) AS total')->groupBy('organization_id')->orderByDesc('total')->limit(8)->pluck('total', 'organization_id');
        $names = $this->names($byOrganization->keys()->all(), $locale);

        $recent = $query()->latest('created_at')->limit(8)->get(['id', 'reference_number', 'status', 'organization_id', 'submitted_at', 'created_at']);
        $recentNames = $this->names($recent->pluck('organization_id')->filter()->unique()->all(), $locale);

        return [
            'figures' => [
                'total' => (int) $counts->sum(),
                'open' => $sum($open),
                'waiting' => $sum([ServiceFeedbackStatus::Pending->value, ServiceFeedbackStatus::Unrouted->value]),
                'unrouted' => $sum([ServiceFeedbackStatus::Unrouted->value]),
                'in_progress' => $sum([ServiceFeedbackStatus::Assigned->value, ServiceFeedbackStatus::UnderReview->value, ServiceFeedbackStatus::Reviewed->value]),
                'outcome' => $sum([ServiceFeedbackStatus::Resolved->value, ServiceFeedbackStatus::Accepted->value, ServiceFeedbackStatus::NotAdopted->value]),
                'closed' => $sum([ServiceFeedbackStatus::Closed->value]),
                'mine' => $query()->whereIn('status', $open)->whereIn('id', $this->assignedTo($user))->count(),
                // ADR 0033: open cases whose current level's deadline has passed.
                'overdue' => $query()->whereIn('status', $open)->whereIn('id', PublicFeedbackAssignment::query()->overdue()->select('submission_id'))->count(),
                'older_than_30_days' => $query()->whereIn('status', $open)->where('created_at', '<', now()->subDays(30))->count(),
            ],
            'byStatus' => collect($statusOrder)->mapWithKeys(fn (string $s) => [$s => (int) ($counts[$s] ?? 0)])->all(),
            'months' => $months,
            'byOrganization' => $byOrganization->map(fn ($total, $id) => ['id' => (string) $id, 'name' => $names[$id] ?? '—', 'total' => (int) $total])->values()->all(),
            'recent' => $recent->map(fn ($row): array => [
                'id' => (string) $row->getKey(),
                'reference' => $row->reference_number,
                'status' => $row->status instanceof ServiceFeedbackStatus ? $row->status->value : (string) $row->status,
                'tip_type' => null,
                'organization' => $recentNames[$row->organization_id] ?? null,
                'submitted_at' => ($row->submitted_at ?? $row->created_at)?->toIso8601String(),
                'created_at' => $row->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * Institution names through the Core directory (a handful of ids).
     *
     * @param  list<string>  $ids
     * @return array<string, string|null>
     */
    private function names(array $ids, string $locale): array
    {
        $names = [];
        foreach ($ids as $id) {
            $organization = $this->organizations->find((string) $id);
            $names[$id] = $organization === null ? null : ($locale === 'am' ? ($organization->nameAm ?: $organization->nameEn) : $organization->nameEn);
        }

        return $names;
    }

    /** Submission ids whose open assignment the user handles: in person, by role or by work unit. */
    private function assignedTo(User $user): \Illuminate\Database\Eloquent\Builder
    {
        $roleIds = $user->roles->pluck('id')->map(fn ($id): string => (string) $id)->all();
        // ADR 0034: designated handling units reach their named members only.
        $unitIds = app(PublicCaseHandlingUnitService::class)->handledUnitIdsFor($user);

        return PublicFeedbackAssignment::query()->whereNull('ended_at')->select('submission_id')
            ->where(function ($q) use ($user, $roleIds, $unitIds): void {
                $q->where(fn ($q) => $q->where('handler_type', PublicFeedbackHandlerType::User->value)->where('handler_id', (string) $user->getKey()));
                if ($roleIds !== []) {
                    $q->orWhere(fn ($q) => $q->where('handler_type', PublicFeedbackHandlerType::Role->value)->whereIn('handler_id', $roleIds));
                }
                if ($unitIds !== []) {
                    $q->orWhere(fn ($q) => $q->where('handler_type', PublicFeedbackHandlerType::OrganizationUnit->value)->whereIn('handler_id', $unitIds));
                }
            });
    }
}
