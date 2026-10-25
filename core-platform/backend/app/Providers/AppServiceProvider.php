<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\ApiClient;
use App\Models\CardRequest;
use App\Models\Employee;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\User;
use App\Services\CoreSettings;
use App\Services\DomainEvents;
use App\Services\OrganizationScope;
use App\Support\CorePermissions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One scope cache per request or job.
        $this->app->scoped(OrganizationScope::class);
        $this->app->scoped(DomainEvents::class);
    }

    public function boot(): void
    {
        // migrate:fresh / refresh / reset and db:wipe refuse to run in production.
        DB::prohibitDestructiveCommands($this->app->isProduction());

        // Record identifiers are UUIDs (accounts are numbered): anything else is a
        // 404 before it reaches the database, where PostgreSQL would reject it.
        foreach (['organization', 'organizationUnit', 'employee', 'position', 'positionEstablishment', 'unit', 'card', 'cardRequest', 'establishment', 'hierarchyVersion', 'apiClient', 'webhook', 'delivery', 'id'] as $parameter) {
            Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
        }
        Route::pattern('user', '[0-9]+');

        // A record outside the signed-in person's organization scope is never loaded:
        // its route answers 404, whatever page or API reached it (ADR 0008).
        $inScope = fn (string $model, \Closure $limit): \Closure => function (string $value) use ($model, $limit): mixed {
            $principal = request()->user();
            if ($principal instanceof ApiClient) {
                return $value; // the integration routes resolve and scope their own records
            }
            $query = $model::query();
            if (request()->route()?->allowsTrashedBindings() && in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
                $query->withTrashed();
            }
            if ($principal instanceof User && ($ids = app(OrganizationScope::class)->organizationIds($principal)) !== null) {
                $limit($query, $ids);
            }

            return $query->findOrFail($value);
        };
        $byOrganization = fn (Builder $query, array $ids) => $query->whereIn('organization_id', $ids);
        $byPlacement = fn (string $path): \Closure => fn (Builder $query, array $ids) => $query->whereHas($path, fn (Builder $a) => $a->whereIn('organization_id', $ids));
        Route::bind('organization', $inScope(Organization::class, fn (Builder $query, array $ids) => $query->whereIn('id', $ids)));
        foreach (['organizationUnit', 'unit'] as $name) {
            Route::bind($name, $inScope(OrganizationUnit::class, $byOrganization));
        }
        Route::bind('position', $inScope(Position::class, $byOrganization));
        foreach (['positionEstablishment', 'establishment'] as $name) {
            Route::bind($name, $inScope(PositionEstablishment::class, $byOrganization));
        }
        Route::bind('employee', $inScope(Employee::class, $byPlacement('currentAssignment')));
        Route::bind('card', $inScope(IdCard::class, $byPlacement('employee.currentAssignment')));
        Route::bind('cardRequest', $inScope(CardRequest::class, $byPlacement('employee.currentAssignment')));

        // Per-account limits from Core settings › Security.
        RateLimiter::for('core-api', fn (Request $request): Limit => Limit::perMinute(app(CoreSettings::class)->int('security.api_rate_limit_per_minute'))
            ->by((string) ($request->user()?->getKey() ?? $request->ip())));
        RateLimiter::for('core-verify', fn (Request $request): Limit => Limit::perMinute(app(CoreSettings::class)->int('security.verification_rate_limit_per_minute'))
            ->by('verify:'.($request->user()?->getKey() ?? $request->ip())));

        // The screens' EUISIS permission names, granted by the Core role.
        Gate::before(fn (User $user, string $ability): ?bool => in_array($ability, CorePermissions::for($user), true) ? true : null);
    }
}
