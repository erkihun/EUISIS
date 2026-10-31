<?php

declare(strict_types=1);

namespace Modules\Providers\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Providers\Domain\Models\ProviderUser;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a provider-portal route on one provider-user permission key
 * (ProviderUserPermissionCatalog); owners and managers hold every key.
 */
class EnsureProviderServicePermission
{
    public function handle(Request $request, Closure $next, string $permissionKey): Response
    {
        /** @var ProviderUser|null $providerUser */
        $providerUser = auth('provider')->user();

        abort_if($providerUser === null || ! $providerUser->canUseServicePermission($permissionKey), 403, __('provider-portal.permission_denied'));

        return $next($request);
    }
}
