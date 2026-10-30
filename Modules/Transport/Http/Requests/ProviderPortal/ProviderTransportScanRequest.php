<?php

declare(strict_types=1);

namespace Modules\Transport\Http\Requests\ProviderPortal;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Providers\Contracts\ProviderPortalSession;
use Modules\Transport\Http\Requests\Concerns\TransportScanRules;

class ProviderTransportScanRequest extends FormRequest
{
    use TransportScanRules;

    public function authorize(): bool
    {
        $portal = app(ProviderPortalSession::class);

        return $portal->actorHasService($this, 'transport') && $portal->can($this, 'provider.transport.scan');
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return $this->scanRules(manualAllowed: app(ProviderPortalSession::class)->can($this, 'provider.transport.manual_exception'));
    }
}
