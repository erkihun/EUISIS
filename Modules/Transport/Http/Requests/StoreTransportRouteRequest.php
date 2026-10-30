<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Requests;

use App\Rules\OrganizationInScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Transport\Http\Requests\Concerns\TransportProviderRequest;

class StoreTransportRouteRequest extends FormRequest
{
    use TransportProviderRequest;

    public function authorize(): bool
    {
        return $this->authorizeTransport('transport-routes.create', 'provider.transport.routes.manage');
    }

    protected function prepareForValidation(): void
    {
        if (! $this->onPortal() && $this->input('assigned_scope_type') === 'citywide') {
            $this->merge(['assigned_organization_id' => null]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'provider_id' => $this->providerIdRules(),
            'route_code' => ['required', 'string', 'max:50', $this->uniqueForProvider('transport_routes', 'route_code')],
            'name_en' => ['required', 'string', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'origin_en' => ['required', 'string', 'max:255'],
            'origin_am' => ['nullable', 'string', 'max:255'],
            'destination_en' => ['required', 'string', 'max:255'],
            'destination_am' => ['nullable', 'string', 'max:255'],
            'distance_km' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'estimated_duration_minutes' => ['nullable', 'integer', 'min:0', 'max:10000'],
            // Who may ride is the city's decision, not the operator's.
            'assigned_organization_id' => $this->onPortal()
                ? ['prohibited']
                : ['nullable', 'uuid', Rule::exists('organizations', 'id')->whereNull('deleted_at'), 'required_unless:assigned_scope_type,citywide', new OrganizationInScope(auth('web')->user())],
            'assigned_scope_type' => $this->onPortal() ? ['prohibited'] : ['required', Rule::in(['self', 'subtree', 'citywide'])],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function boundTransportRecord(): ?Model
    {
        $route = $this->route('route');

        return $route instanceof Model ? $route : null;
    }
}
