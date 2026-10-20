<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Employee;
use App\Models\Provider;
use App\Models\TransportPass;
use App\Models\TransportRoute;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\Transport\TransportRouteAccessService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A transport pass, issued by staff. The employee must be within the issuer's
 * organization scope, the route must be the provider's and open to that
 * employee, and an employee holds at most one active pass per provider and
 * route over any period.
 */
class StoreTransportPassRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('web')->user()?->can('transport-passes.create') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'employee_id' => [
                'required',
                'uuid',
                Rule::exists('employees', 'id'),
                function (string $attribute, mixed $value, Closure $fail): void {
                    $employee = Employee::query()->with('currentAssignment')->find($value);
                    if ($employee !== null && ! app(OrganizationScopeService::class)->canAccessEmployee($this->user(), $employee)) {
                        $fail(__('transport.employee_out_of_scope'));
                    }
                },
            ],
            'provider_id' => [
                'required',
                'uuid',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $provider = Provider::query()->find($value);
                    if ($provider === null || ! $provider->hasService('transport')) {
                        $fail(__('transport.provider_required'));
                    }
                },
            ],
            'transport_route_id' => [
                'nullable',
                'uuid',
                Rule::exists('transport_routes', 'id')->where('provider_id', (string) $this->input('provider_id'))->whereNull('deleted_at'),
            ],
            'boarding_stop_id' => ['nullable', 'uuid', Rule::exists('transport_route_stops', 'id')->where('transport_route_id', (string) $this->input('transport_route_id'))->whereNull('deleted_at')],
            'evening_stop_id' => ['nullable', 'uuid', Rule::exists('transport_route_stops', 'id')->where('transport_route_id', (string) $this->input('transport_route_id'))->whereNull('deleted_at')],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'status' => ['required', Rule::in(['active', 'suspended', 'expired', 'cancelled'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return list<Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $employee = Employee::query()->with('currentAssignment')->find($this->input('employee_id'));
            $route = $this->filled('transport_route_id') ? TransportRoute::query()->find($this->input('transport_route_id')) : null;

            if ($employee !== null && $route !== null && ! app(TransportRouteAccessService::class)->employeeCanUseRoute($employee, $route)) {
                $validator->errors()->add('transport_route_id', __('transport.route_not_open_to_employee'));
            }

            if ($this->input('status') === 'active' && $this->overlapsAnotherActivePass()) {
                $validator->errors()->add('valid_from', __('transport.pass_overlaps'));
            }
        }];
    }

    private function overlapsAnotherActivePass(): bool
    {
        $from = (string) $this->input('valid_from');
        $until = $this->input('valid_until');
        $current = $this->route('pass');

        return TransportPass::query()
            ->where('employee_id', $this->input('employee_id'))
            ->where('provider_id', $this->input('provider_id'))
            ->where('status', 'active')
            ->when($this->filled('transport_route_id'),
                fn ($query) => $query->where(fn ($inner) => $inner->whereNull('transport_route_id')->orWhere('transport_route_id', $this->input('transport_route_id'))),
                fn ($query) => $query, // a pass for every route overlaps any route pass
            )
            ->when($current instanceof TransportPass, fn ($query) => $query->whereKeyNot($current->getKey()))
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $from))
            ->when($until !== null && $until !== '', fn ($query) => $query->whereDate('valid_from', '<=', (string) $until))
            ->exists();
    }
}
