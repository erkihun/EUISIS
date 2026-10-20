<?php

declare(strict_types=1);

namespace App\Http\Requests\ProviderPortal;

use App\Models\ProviderUser;
use App\Models\TransportInvoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A provider's claim for a period: what it says it delivered and what it
 * asks for. The verified figures and the approved amount are never on this
 * form; they come from the statement the city computes.
 */
class ProviderTransportInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ProviderUser|null $providerUser */
        $providerUser = auth('provider')->user();
        if ($providerUser === null || ! $providerUser->hasService('transport') || ! $providerUser->canUseServicePermission('provider.transport.invoices.manage')) {
            return false;
        }
        $invoice = $this->route('invoice');

        return ! $invoice instanceof TransportInvoice || $invoice->provider_id === $providerUser->provider_id;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        /** @var ProviderUser $providerUser */
        $providerUser = auth('provider')->user();
        $invoice = $this->route('invoice');

        return [
            'invoice_number' => ['nullable', 'string', 'max:60', Rule::unique('transport_invoices', 'invoice_number')->where('provider_id', $providerUser->provider_id)->ignore($invoice instanceof TransportInvoice ? $invoice->id : null)],
            'transport_contract_id' => ['nullable', 'uuid', Rule::exists('transport_contracts', 'id')->where('provider_id', $providerUser->provider_id)],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'claimed_boardings' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'claimed_trips' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'claimed_employee_days' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'claimed_amount' => ['required', 'numeric', 'regex:/^\d+(\.\d{1,2})?$/', 'min:0', 'max:999999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }
}
