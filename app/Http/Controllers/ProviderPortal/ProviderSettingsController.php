<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProviderPortal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ProviderPortal\Concerns\FormatsProviderPortalData;
use App\Services\ProviderPortal\ProviderPortalContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProviderSettingsController extends Controller
{
    use FormatsProviderPortalData;

    public function __invoke(Request $request, ProviderPortalContext $context): Response
    {
        $provider = $context->selectedProvider($request);
        abort_if($provider === null, 403, __('provider-portal.not_assigned'));

        return Inertia::render('ProviderPortal/Settings', [
            ...$this->portalPayload($request, $context, $provider),
            'provider' => $this->providerOption($provider),
        ]);
    }
}
