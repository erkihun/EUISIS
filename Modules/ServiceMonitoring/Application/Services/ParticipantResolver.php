<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Services;

use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Modules\ServiceMonitoring\Domain\Enums\ReportingLevel;
use Modules\ServiceMonitoring\Domain\Models\Participant;
use Modules\ServiceMonitoring\Domain\Models\ReportingPeriod;

/**
 * Scope-aware lookups in the participant registry: who reports for a period,
 * a sub-city's woredas, and the participant(s) a signed-in user reports for.
 */
class ParticipantResolver
{
    public function __construct(
        private readonly ServiceMonitoringAccess $access,
        private readonly OrganizationScopeService $scope,
    ) {}

    /**
     * Participants expected to report for a period: active on its last day.
     *
     * @return Builder<Participant>
     */
    public function expectedFor(ReportingPeriod $period): Builder
    {
        return Participant::query()->activeOn($period->ends_on->toDateString());
    }

    /** @return Builder<Participant> */
    public function visibleExpectedFor(ReportingPeriod $period, User $user): Builder
    {
        return $this->access->scopeParticipants($this->expectedFor($period), $user);
    }

    /** @return Collection<int, Participant> */
    public function woredasOf(Participant $subCity, ReportingPeriod $period): Collection
    {
        return $this->expectedFor($period)
            ->where('parent_participant_id', $subCity->id)
            ->where('level', ReportingLevel::Woreda->value)
            ->with('organization:id,name_en,name_am')
            ->get();
    }

    /** @return Collection<int, Participant> */
    public function subCities(ReportingPeriod $period): Collection
    {
        return $this->expectedFor($period)->where('level', ReportingLevel::SubCity->value)->with('organization:id,name_en,name_am')->get();
    }

    public function activeForOrganization(string $organizationId, ?string $date = null): ?Participant
    {
        return Participant::query()->where('organization_id', $organizationId)
            ->activeOn($date ?? now()->toDateString())
            ->first();
    }

    /**
     * The participants the user reports for: those whose organization the user
     * is explicitly assigned to (scope records), or their own employee
     * placement when they have none. Never the whole subtree.
     *
     * @return Collection<int, Participant>
     */
    public function reportingFor(User $user, string $date): Collection
    {
        $organizations = $this->scope->assignedOrganizationIds($user);
        if ($organizations === [] && $user->employee?->currentAssignment?->organization_id !== null) {
            $organizations = [(string) $user->employee->currentAssignment->organization_id];
        }
        if ($organizations === []) {
            return new Collection;
        }

        return Participant::query()->whereIn('organization_id', $organizations)->activeOn($date)->with('organization:id,name_en,name_am')->get();
    }
}
