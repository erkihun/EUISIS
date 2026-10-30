<?php

declare(strict_types=1);

namespace Modules\Providers\Providers;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Providers\Application\Policies\ProviderUserPolicy;
use Modules\Providers\Application\Queries\ProviderDirectoryQuery;
use Modules\Providers\Application\Services\NoPortalLocations;
use Modules\Providers\Application\Services\ProviderPortalContext;
use Modules\Providers\Application\Services\ProviderRegistryService;
use Modules\Providers\Contracts\PortalLocationSource;
use Modules\Providers\Contracts\ProviderDirectory;
use Modules\Providers\Contracts\ProviderPortalSession;
use Modules\Providers\Contracts\ProviderRegistry;
use Modules\Providers\Domain\Models\Provider;
use Modules\Providers\Domain\Models\ProviderPasswordResetCode;
use Modules\Providers\Domain\Models\ProviderService;
use Modules\Providers\Domain\Models\ProviderType;
use Modules\Providers\Domain\Models\ProviderUser;
use Modules\Providers\Domain\Models\ProviderUserServicePermission;
use Modules\Providers\Http\Middleware\EnsureProviderPortalUser;
use Modules\Providers\Http\Middleware\EnsureProviderServiceEnabled;
use Modules\Providers\Http\Middleware\EnsureProviderServicePermission;
use Modules\Providers\Http\Middleware\EnsureProviderServiceScope;
use Modules\Providers\Http\Middleware\SetProviderPortalContext;
use Modules\Providers\Infrastructure\Console\MigrateLegacyProviderUsers;

/**
 * Everything the application needs to know about the Providers module
 * (ADR 0017): provider records (providers, their types and services) and their
 * portal users, the provider portal's sign-in, context and permission
 * middleware, its policies, and the names its records keep in the database.
 * Service providers (service_providers: one service point of one service type,
 * for entitlements and the partner API) belong to service delivery, not here.
 *
 * The guest redirect of the provider guard stays in bootstrap/app.php with
 * the other guards' redirects.
 */
final class ProvidersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProviderRegistry::class, ProviderRegistryService::class);
        $this->app->bind(ProviderPortalSession::class, ProviderPortalContext::class);
        $this->app->bind(ProviderDirectory::class, ProviderDirectoryQuery::class);
        // A service module that runs portal locations (the cafeteria) binds its own.
        $this->app->bindIf(PortalLocationSource::class, NoPortalLocations::class);
    }

    public function boot(): void
    {
        // Moved models keep their old class names in the database (roles, audit logs, tokens, notifiables).
        Relation::morphMap([
            'App\Models\Provider' => Provider::class,
            'App\Models\ProviderPasswordResetCode' => ProviderPasswordResetCode::class,
            'App\Models\ProviderService' => ProviderService::class,
            'App\Models\ProviderType' => ProviderType::class,
            'App\Models\ProviderUser' => ProviderUser::class,
            'App\Models\ProviderUserServicePermission' => ProviderUserServicePermission::class,
        ]);

        // Policy discovery only looks in App\Policies.
        Gate::policy(ProviderUser::class, ProviderUserPolicy::class);

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('provider.scope', EnsureProviderServiceScope::class);
        $router->aliasMiddleware('provider.portal', EnsureProviderPortalUser::class);
        $router->aliasMiddleware('provider.service', EnsureProviderServiceEnabled::class);
        $router->aliasMiddleware('provider.permission', EnsureProviderServicePermission::class);
        $router->aliasMiddleware('provider.portal.context', SetProviderPortalContext::class);

        if ($this->app->runningInConsole()) {
            $this->commands([MigrateLegacyProviderUsers::class]);
        }
    }
}
