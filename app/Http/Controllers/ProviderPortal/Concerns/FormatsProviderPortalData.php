<?php

declare(strict_types=1);

namespace App\Http\Controllers\ProviderPortal\Concerns;

use App\Models\CafeteriaProvider;
use App\Models\User;
use App\Services\ProviderPortal\ProviderPortalContext;
use Illuminate\Http\Request;

trait FormatsProviderPortalData
{
    /**
     * For created_by / updated_by columns, which reference staff users. A
     * provider portal operator (provider_users, UUID key) is not one, so the
     * column is left unset rather than given a key from another table.
     */
    private function staffUserId(Request $request): ?int
    {
        return $request->user() instanceof User ? (int) $request->user()->getKey() : null;
    }

    /** @return array<string, mixed> */
    private function portalPayload(Request $request, ProviderPortalContext $context, ?CafeteriaProvider $provider = null): array
    {
        $payload = $context->providerPayload($request);

        return [
            'providers' => $payload['providers'],
            'selected_provider_id' => $provider?->id ?? $payload['selected_provider_id'],
        ];
    }

    /** @return array<string, mixed> */
    private function providerOption(CafeteriaProvider $provider): array
    {
        return [
            'id' => $provider->id,
            'code' => $provider->code,
            'name_en' => $provider->name_en,
            'name_am' => $provider->name_am,
            'contact_person' => $provider->contact_person,
            'phone_number' => $provider->phone_number,
            'email' => $provider->email,
            'location' => $provider->location,
            'is_active' => (bool) $provider->is_active,
        ];
    }
}
