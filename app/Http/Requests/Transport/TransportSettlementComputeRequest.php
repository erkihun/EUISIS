<?php

declare(strict_types=1);

namespace App\Http\Requests\Transport;

use Illuminate\Foundation\Http\FormRequest;

class TransportSettlementComputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('transport-settlements.manage') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'provider_id' => ['required', 'uuid', 'exists:providers,id'],
            'transport_contract_id' => ['required', 'uuid', 'exists:transport_contracts,id'],
            'transport_invoice_id' => ['nullable', 'uuid', 'exists:transport_invoices,id'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
        ];
    }
}
