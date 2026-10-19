<?php

declare(strict_types=1);

namespace App\Http\Requests\ProviderPortal;

use App\Http\Requests\Concerns\TransportScanRules;
use App\Models\ProviderUser;
use Illuminate\Foundation\Http\FormRequest;

class ProviderTransportScanRequest extends FormRequest
{
    use TransportScanRules;

    public function authorize(): bool
    {
        /** @var ProviderUser|null $providerUser */
        $providerUser = auth('provider')->user();

        return ($providerUser?->hasService('transport') ?? false)
            && $providerUser->canUseServicePermission('provider.transport.scan');
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        /** @var ProviderUser|null $providerUser */
        $providerUser = auth('provider')->user();

        return $this->scanRules(manualAllowed: $providerUser?->canUseServicePermission('provider.transport.manual_exception') ?? false);
    }
}
