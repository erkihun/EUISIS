<?php

declare(strict_types=1);

namespace App\Http\Requests\Transport;

use App\Enums\Transport\TransportDisputeType;
use App\Models\TransportSettlement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** An adjustment (staff) or a dispute (provider portal) on a statement. */
class TransportAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->routeIs('provider.portal.*')) {
            $providerUser = auth('provider')->user();
            $settlement = $this->route('settlement');

            return $providerUser !== null && $settlement instanceof TransportSettlement && $settlement->provider_id === $providerUser->provider_id
                && $providerUser->hasService('transport') && $providerUser->canUseServicePermission('provider.transport.invoices.manage');
        }

        return $this->user()?->can('transport-settlements.manage') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'kind' => ['nullable', Rule::in(['usage', 'financial'])],
            'dispute_type' => ['nullable', Rule::in(TransportDisputeType::values())],
            'amount' => ['required', 'numeric', 'regex:/^-?\d+(\.\d{1,2})?$/', 'min:-999999999', 'max:999999999'],
            'quantity' => ['nullable', 'integer', 'min:-100000', 'max:100000'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'evidence' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }
}
