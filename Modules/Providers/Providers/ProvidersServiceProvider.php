<?php

declare(strict_types=1);

namespace Modules\Providers\Providers;

use App\Backbone\Contracts\ProviderCodeLookup;
use App\Enums\CodeRuleEntityType;
use App\Services\CodeGeneration\CodeRuleTargetRegistry;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Providers\Application\Policies\ProviderUserPolicy;
use Modules\Providers\Application\Policies\ServiceProviderPolicy;
use Modules\Providers\Application\Services\NoPortalLocations;
use Modules\Providers\Application\Services\ServiceProviderCodeLookup;
use Modules\Providers\Contracts\PortalLocationSource;
use Modules\Providers\Domain\Models\Provider;
use Modules\Providers\Domain\Models\ProviderPasswordResetCode;
use Modules\Providers\Domain\Models\ProviderService;
use Modules\Providers\Domain\Models\ProviderType;
use Modules\Providers\Domain\Models\ProviderUser;
use Modules\Providers\Domain\Models\ProviderUserServicePermission;
use Modules\Providers\Domain\Models\ServiceProvider as ServiceProviderModel;
use Modules\Providers\Domain\Models\ServiceProviderUser;
use Modules\Providers\Http\Middleware\EnsureProviderPortalUser;
use Modules\Providers\Http\Middleware\EnsureProviderServiceEnabled;
use Modules\Providers\Http\Middleware\EnsureProviderServicePermission;
use Modules\Providers\Http\Middleware\EnsureProviderServiceScope;
use Modules\Providers\Http\Middleware\SetProviderPortalContext;
use Modules\Providers\Infrastructure\Console\MigrateLegacyProviderUsers;

/**
 * Everything the application needs to know about the Providers module
 * (ADR 0017): provider records and their portal users, the provider portal's
 * sign-in, context and permission middleware, its policies, the backbone's
 * provider-code lookup, and the names its records keep in the database.
 *
 * The guest redirect of the provider guard stays in bootstrap/app.php with
 * the other guards' redirects.
 */
final class ProvidersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProviderCodeLookup::class, ServiceProviderCodeLookup::class);
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
            'App\Models\ServiceProvider' => ServiceProviderModel::class,
            'App\Models\ServiceProviderUser' => ServiceProviderUser::class,
        ]);

        // Policy discovery only looks in App\Policies.
        Gate::policy(ProviderUser::class, ProviderUserPolicy::class);
        Gate::policy(ServiceProviderModel::class, ServiceProviderPolicy::class);

        CodeRuleTargetRegistry::registerTarget(CodeRuleEntityType::ServiceProvider, [
            'model' => ServiceProviderModel::class,
            'table' => 'service_providers',
            'code_column' => 'code',
            'label' => 'Service Provider Code',
            'immutable_after_create' => false,
        ]);
        CodeRuleTargetRegistry::registerCollisionCheck(CodeRuleEntityType::ServiceProvider, ServiceProviderModel::class, 'code');

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
