<?php

declare(strict_types=1);

namespace Modules\Providers\Contracts;

use Illuminate\Http\Request;
use Modules\Providers\Contracts\Data\PortalActor;

/**
 * Who is signed in to the provider portal, for the service modules' portal
 * pages: the provider as an id, its services, and the props the portal
 * layout shares on every page. Checks are live, never cached.
 */
interface ProviderPortalSession
{
    /** The signed-in portal user's provider while that provider is active; null otherwise. */
    public function providerId(Request $request): ?string;

    /** Whether the provider runs the service: the module is enabled and the provider's service is active. */
    public function providerHasService(string $providerId, string $serviceCode): bool;

    /** The signed-in portal user; null when nobody is signed in to the portal. */
    public function actor(Request $request): ?PortalActor;

    /** Whether the signed-in portal user may use the permission (owners and managers may use all); false when nobody is signed in. */
    public function can(Request $request, string $permissionKey): bool;

    /** Whether the signed-in user's provider runs the service (whatever the provider's status, as before); false when nobody is signed in. */
    public function actorHasService(Request $request, string $serviceCode): bool;

    /**
     * The props the provider-portal layout shares on every page: the locations
     * to switch between, the selected one, and the signed-in user, provider,
     * enabled services and page permissions.
     *
     * @return array<string, mixed>
     */
    public function providerPayload(Request $request): array;
}
