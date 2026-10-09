<?php

declare(strict_types=1);

namespace App\Services\Transport;

use App\Models\Employee;
use App\Models\IdCard;
use App\Models\Provider;
use App\Models\TransportPass;
use App\Models\TransportRoute;
use Illuminate\Support\Carbon;

class TransportEligibilityService
{
    public function __construct(
        private readonly TransportRouteAccessService $routeAccess,
    ) {}

    /** @return array{eligible: bool, reason: string|null, pass: TransportPass|null} */
    public function check(Employee $employee, IdCard $card, Provider $provider, ?TransportRoute $route = null, ?Carbon $date = null): array
    {
        $date ??= Carbon::today();

        if (! $provider->hasService('transport')) {
            return $this->deny('provider_transport_service_disabled');
        }

        if ($employee->currentAssignment === null) {
            return $this->deny('employee_assignment_missing');
        }

        if ($route !== null && ! $this->routeAccess->employeeCanUseRoute($employee, $route)) {
            return $this->deny('route_not_allowed_for_employee');
        }

        $pass = TransportPass::query()
            ->where('employee_id', $employee->id)
            ->where('provider_id', $provider->id)
            ->when($route !== null, fn ($query) => $query->where(function ($inner) use ($route): void {
                $inner->whereNull('transport_route_id')->orWhere('transport_route_id', $route->id);
            }))
            ->where('status', 'active')
            ->whereDate('valid_from', '<=', $date->toDateString())
            ->where(function ($query) use ($date): void {
                $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $date->toDateString());
            })
            ->first();

        if ($pass === null) {
            return $this->deny('active_transport_pass_missing');
        }

        return ['eligible' => true, 'reason' => null, 'pass' => $pass];
    }

    /** @return array{eligible: false, reason: string, pass: null} */
    private function deny(string $reason): array
    {
        return ['eligible' => false, 'reason' => $reason, 'pass' => null];
    }
}
