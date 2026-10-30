<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Requests;

use App\Models\Provider;
use App\Rules\OrganizationInScope;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Transport\Domain\Enums\TransportSettlementModel;
use Modules\Transport\Domain\Enums\TransportSettlementPeriod;

/** A contract version. Rates are entered, never computed; each is a two-decimal amount. */
class StoreTransportContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can($this->route('contract') ? 'transport-contracts.update' : 'transport-contracts.create') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $terms = [];
        foreach (TransportSettlementModel::allTerms() as $key) {
            $terms['terms.'.$key] = ['nullable', 'numeric', 'min:0', 'max:999999999999', 'regex:/^\d+(\.\d{1,2})?$/'];
        }

        return [
            'contract_reference' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9\-\/_.]+$/'],
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id', new OrganizationInScope($this->user())],
            'provider_id' => ['required', 'uuid', function (string $attribute, mixed $value, Closure $fail): void {
                $provider = Provider::query()->find($value);
                if ($provider === null || ! $provider->hasService('transport')) {
                    $fail(__('transport.provider_required'));
                }
            }],
            'currency_code' => ['required', 'string', 'size:3', 'alpha'],
            'settlement_model' => ['required', Rule::in(TransportSettlementModel::values())],
            'settlement_period' => ['required', Rule::in(TransportSettlementPeriod::values())],
            'terms' => ['nullable', 'array'],
            ...$terms,
            'service_coverage' => ['nullable', 'string', 'max:4000'],
            'terms_reference' => ['nullable', 'string', 'max:255'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'agreement' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
        ];
    }
}
