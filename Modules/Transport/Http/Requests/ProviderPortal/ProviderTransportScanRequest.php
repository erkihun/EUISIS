<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Requests\ProviderPortal;

use App\Models\ProviderUser;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Transport\Http\Requests\Concerns\TransportScanRules;

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
