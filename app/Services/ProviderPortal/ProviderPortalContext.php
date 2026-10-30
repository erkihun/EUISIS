<?php

declare(strict_types=1);

namespace App\Services\ProviderPortal;

use App\Models\Provider;
use App\Models\ProviderUser;
use App\Support\Features;
use Illuminate\Http\Request;
use Modules\Cafeteria\Contracts\CafeteriaPortalLocations;

class ProviderPortalContext
{
    public function providerUser(Request $request): ?ProviderUser
    {
        /** @var ProviderUser|null $providerUser */
        $providerUser = auth('provider')->user();

        return $providerUser;
    }

    public function provider(Request $request): ?Provider
    {
        $provider = $this->providerUser($request)?->provider;

        return $provider instanceof Provider && $provider->status === 'active'
            ? $provider
            : null;
    }

    /**
     * Where a signed-in provider user lands: the cafeteria dashboard, or the
     * transport dashboard when the provider runs transport but no cafeteria
     * (the cafeteria dashboard would answer them with a 403).
     */
    public function homeUrl(ProviderUser $providerUser): string
    {
        if (! $providerUser->hasService('cafeteria') && $providerUser->hasService('transport')) {
            return route('provider.portal.transport.dashboard');
        }

        return route('provider.portal.dashboard');
    }

    /** @return array<string, mixed> */
    public function providerPayload(Request $request): array
    {
        $provider = $this->provider($request);
        // The cafeteria locations the portal can switch between belong to the Cafeteria module.
        $cafeterias = app(CafeteriaPortalLocations::class)->selection($request);

        return [
            'providers' => $cafeterias->locations,
            'selected_provider_id' => $cafeterias->selectedId,
            'providerPortal' => $provider ? $this->formatProviderPortal($request, $provider) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function formatProviderPortal(Request $request, Provider $provider): array
    {
        $providerUser = $this->providerUser($request);
        $provider->loadMissing(['providerType', 'services.serviceType']);

        return [
            'isPortal' => true,
            'user' => $providerUser ? [
                'id' => $providerUser->id,
                'name' => $providerUser->name,
                'email' => $providerUser->email,
                'username' => $providerUser->username,
                'initials' => $providerUser->initials(),
                'status' => $providerUser->status,
                'provider_role' => $providerUser->provider_role,
                'must_change_password' => $providerUser->must_change_password,
            ] : null,
            'provider' => [
                'id' => $provider->id,
                'code' => $provider->provider_code,
                'name_en' => $provider->name_en,
                'name_am' => $provider->name_am,
                'logo_url' => $provider->logo_path ? asset('storage/'.$provider->logo_path) : null,
                'type' => $provider->providerType?->code,
            ],
            'enabledServices' => $provider->services
                ->filter(fn ($service): bool => $service->status === 'active' && (bool) $service->serviceType?->is_active)
                ->map(fn ($service): array => [
                    'code' => $service->serviceType->code,
                    'name' => $service->serviceType->name_en,
                    'route' => $service->serviceType->route_prefix,
                    'icon' => $service->serviceType->icon,
                ])
                ->values()
                ->all(),
            'can' => [
                'cafeteriaScan' => $providerUser?->hasService('cafeteria') ?? false,
                'cafeteriaTransactionsView' => $providerUser?->hasService('cafeteria') ?? false,
                'cafeteriaTransactionsExport' => $providerUser?->hasService('cafeteria') ?? false,
                'cafeteriaMenusManage' => $providerUser?->hasService('cafeteria') ?? false,
                'cafeteriaOrdersManage' => $providerUser?->hasService('cafeteria') ?? false,
                'transportDashboardView' => $providerUser?->hasService('transport') ?? false,
                'transportScan' => $this->transportCan($providerUser, 'provider.transport.scan'),
                'transportTransactionsView' => $this->transportCan($providerUser, 'provider.transport.reports.view'),
                'transportRoutesManage' => $this->transportCan($providerUser, 'provider.transport.routes.manage'),
                'transportVehiclesManage' => $this->transportCan($providerUser, 'provider.transport.vehicles.manage'),
                'transportDriversManage' => $this->transportCan($providerUser, 'provider.transport.drivers.manage'),
                'transportTripsManage' => $this->transportCan($providerUser, 'provider.transport.trips.manage'),
                'transportReportsView' => $this->transportCan($providerUser, 'provider.transport.reports.view'),
                'transportDispatch' => $this->transportCan($providerUser, 'provider.transport.dispatch'),
                'transportManualException' => $this->transportCan($providerUser, 'provider.transport.manual_exception'),
                'transportManifestsView' => $this->transportCan($providerUser, 'provider.transport.manifests.view'),
                'transportExceptionsView' => $this->transportCan($providerUser, 'provider.transport.exceptions.view'),
                'transportDevicesView' => $this->transportCan($providerUser, 'provider.transport.devices.view'),
                'transportInvoicesManage' => Features::enabled('transport_settlement') && $this->transportCan($providerUser, 'provider.transport.invoices.manage'),
                'reportsView' => $providerUser?->hasService('cafeteria') ?? false,
            ],
        ];
    }

    /** The page links a transport provider user may follow (mirrors the route middleware). */
    private function transportCan(?ProviderUser $providerUser, string $permissionKey): bool
    {
        return $providerUser !== null
            && $providerUser->hasService('transport')
            && $providerUser->canUseServicePermission($permissionKey);
    }
}
