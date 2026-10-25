<?php

declare(strict_types=1);

namespace App\Services\Transport;

use App\Enums\Transport\TransportEligibilitySubject;
use App\Models\Employee;
use App\Models\IdCard;
use App\Models\Provider;
use App\Models\TransportEligibility;
use App\Models\TransportPass;
use App\Models\TransportRoute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Whether an employee may board with a provider on a route today, and on
 * what basis. The answer is a snapshot the boarding keeps, so a later change
 * of eligibility never rewrites why a past boarding was accepted.
 *
 * Basis, in order of precedence:
 *  - pass: the employee's route assignment with this provider (TransportPass)
 *  - employee_grant / organization_grant: a TransportEligibility naming the
 *    employee or their office, when the settings let a grant board without
 *    an individual assignment
 *  - pay_as_you_go / pass_not_required: the legacy switches
 */
class TransportEligibilityService
{
    public const BASIS_PASS = 'pass';

    public const BASIS_EMPLOYEE_GRANT = 'employee_grant';

    public const BASIS_ORGANIZATION_GRANT = 'organization_grant';

    public const BASIS_PAY_AS_YOU_GO = 'pay_as_you_go';

    public const BASIS_PASS_NOT_REQUIRED = 'pass_not_required';

    public function __construct(
        private readonly TransportRouteAccessService $routeAccess,
        private readonly TransportSettings $settings,
    ) {}

    /**
     * @return array{eligible: bool, reason: string|null, pass: TransportPass|null, grant: TransportEligibility|null, basis: string|null, alternative_route: bool, snapshot: array<string, mixed>}
     */
    public function check(Employee $employee, ?IdCard $card, Provider $provider, ?TransportRoute $route = null, ?Carbon $date = null): array
    {
        $date ??= Carbon::today();
        $day = $date->toDateString();

        if (! $provider->hasService('transport')) {
            return $this->deny('provider_transport_service_disabled', $employee, $card, $day);
        }

        if ($employee->currentAssignment === null) {
            return $this->deny('employee_assignment_missing', $employee, $card, $day);
        }

        if ($route !== null && ! $route->is_active) {
            return $this->deny('route_inactive', $employee, $card, $day);
        }

        if ($route !== null && ! $this->routeAccess->organizationCanUseRoute($employee->currentAssignment?->organization_id, $route)) {
            return $this->deny('route_not_allowed_for_employee', $employee, $card, $day);
        }

        $passes = TransportPass::query()
            ->where('employee_id', $employee->id)
            ->where('provider_id', $provider->id)
            ->validOn($day)
            ->orderByRaw('transport_route_id IS NULL')
            ->get();

        $assigned = $route === null ? $passes->first() : $passes->first(fn (TransportPass $p) => $p->transport_route_id === null || $p->transport_route_id === $route->id);

        if ($assigned !== null) {
            return $this->allow(self::BASIS_PASS, $employee, $card, $day, pass: $assigned);
        }

        // An assignment with this provider on another route: the alternative-route policy decides.
        if ($passes->isNotEmpty() && $route !== null) {
            if ($this->settings->alternativeRoutePolicy() === TransportSettings::ALTERNATIVE_ROUTE_DENY) {
                return $this->deny('route_not_assigned', $employee, $card, $day, pass: $passes->first());
            }

            return $this->allow(self::BASIS_PASS, $employee, $card, $day, pass: $passes->first(), alternative: true);
        }

        // An assignment with another provider, and the policy lets any eligible employee ride any serving route.
        if ($route !== null && $this->settings->alternativeRoutePolicy() === TransportSettings::ALTERNATIVE_ROUTE_ANY_ELIGIBLE) {
            $elsewhere = TransportPass::query()->where('employee_id', $employee->id)->validOn($day)->first();
            if ($elsewhere !== null) {
                return $this->allow(self::BASIS_PASS, $employee, $card, $day, pass: $elsewhere, alternative: true);
            }
        }

        if ($this->settings->organizationGrantsBoardWithoutPass()) {
            $grant = $this->grantFor($employee, $provider, $day);
            if ($grant !== null) {
                $basis = $grant->subject_type === TransportEligibilitySubject::Employee ? self::BASIS_EMPLOYEE_GRANT : self::BASIS_ORGANIZATION_GRANT;

                return $this->allow($basis, $employee, $card, $day, grant: $grant);
            }
        }

        if ($this->settings->allowPayAsYouGo()) {
            return $this->allow(self::BASIS_PAY_AS_YOU_GO, $employee, $card, $day);
        }

        if (! $this->settings->requirePass()) {
            return $this->allow(self::BASIS_PASS_NOT_REQUIRED, $employee, $card, $day);
        }

        return $this->deny('active_transport_pass_missing', $employee, $card, $day);
    }

    /**
     * Whether the provider may carry the employee on some route today, before a
     * route is known (the manual-exception lookup). Same rules as check(),
     * including the any-eligible alternative-route policy that check() applies
     * only once a route is chosen.
     */
    public function mayCarry(Employee $employee, Provider $provider, ?Carbon $date = null): bool
    {
        $date ??= Carbon::today();
        if ($this->check($employee, null, $provider, null, $date)['eligible']) {
            return true;
        }

        return $provider->hasService('transport')
            && $employee->currentAssignment !== null
            && $this->settings->alternativeRoutePolicy() === TransportSettings::ALTERNATIVE_ROUTE_ANY_ELIGIBLE
            && TransportPass::query()->where('employee_id', $employee->id)->validOn($date->toDateString())->exists();
    }

    /** The grant covering the employee on the date: their own, their office's, or an ancestor's subtree grant. */
    public function grantFor(Employee $employee, ?Provider $provider, string $day): ?TransportEligibility
    {
        $organizationId = $employee->currentAssignment?->organization_id;
        $ancestors = $organizationId === null ? [] : $this->routeAccess->ancestorIds($organizationId);

        return TransportEligibility::query()
            ->effectiveOn($day)
            ->when($provider !== null, fn (Builder $q) => $q->where(fn (Builder $inner) => $inner->whereNull('provider_id')->orWhere('provider_id', $provider->id)))
            ->where(function (Builder $query) use ($employee, $organizationId, $ancestors): void {
                $query->where(fn (Builder $q) => $q->where('subject_type', TransportEligibilitySubject::Employee->value)->where('employee_id', $employee->id));
                if ($organizationId !== null) {
                    $query->orWhere(fn (Builder $q) => $q->where('subject_type', TransportEligibilitySubject::Organization->value)->where('organization_id', $organizationId));
                }
                if ($ancestors !== []) {
                    $query->orWhere(fn (Builder $q) => $q->where('subject_type', TransportEligibilitySubject::Organization->value)->where('scope_type', 'subtree')->whereIn('organization_id', $ancestors));
                }
            })
            ->orderByRaw("CASE subject_type WHEN 'employee' THEN 0 ELSE 1 END")
            ->first();
    }

    /** @return array{eligible: true, reason: string|null, pass: TransportPass|null, grant: TransportEligibility|null, basis: string, alternative_route: bool, snapshot: array<string, mixed>} */
    private function allow(string $basis, Employee $employee, ?IdCard $card, string $day, ?TransportPass $pass = null, ?TransportEligibility $grant = null, bool $alternative = false): array
    {
        $reason = in_array($basis, [self::BASIS_PAY_AS_YOU_GO, self::BASIS_PASS_NOT_REQUIRED], true) ? $basis : null;

        return [
            'eligible' => true,
            'reason' => $reason,
            'pass' => $pass,
            'grant' => $grant,
            'basis' => $basis,
            'alternative_route' => $alternative,
            'snapshot' => $this->snapshot($employee, $card, $day, $basis, $pass, $grant, $alternative),
        ];
    }

    /** @return array{eligible: false, reason: string, pass: TransportPass|null, grant: null, basis: null, alternative_route: false, snapshot: array<string, mixed>} */
    private function deny(string $reason, Employee $employee, ?IdCard $card, string $day, ?TransportPass $pass = null): array
    {
        return [
            'eligible' => false,
            'reason' => $reason,
            'pass' => $pass,
            'grant' => null,
            'basis' => null,
            'alternative_route' => false,
            'snapshot' => $this->snapshot($employee, $card, $day, null, $pass, null, false) + ['denied_reason' => $reason],
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(Employee $employee, ?IdCard $card, string $day, ?string $basis, ?TransportPass $pass, ?TransportEligibility $grant, bool $alternative): array
    {
        return [
            'checked_on' => $day,
            'basis' => $basis,
            'employee_status' => $employee->status?->value ?? (string) $employee->status,
            'organization_id' => $employee->currentAssignment?->organization_id,
            'card_id' => $card?->id,
            'card_status' => $card?->status?->value,
            'pass_id' => $pass?->id,
            'assigned_route_id' => $pass?->transport_route_id,
            'eligibility_id' => $grant?->id,
            'alternative_route' => $alternative,
        ];
    }
}
