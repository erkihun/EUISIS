<?php

declare(strict_types=1);

namespace Modules\Cafeteria\Providers;

use App\Models\ServiceTerminal;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Cafeteria\Application\Policies\CafeteriaDayRulePolicy;
use Modules\Cafeteria\Application\Policies\CafeteriaProviderPolicy;
use Modules\Cafeteria\Application\Policies\CafeteriaProviderUserPolicy;
use Modules\Cafeteria\Application\Policies\CafeteriaReportRunPolicy;
use Modules\Cafeteria\Application\Policies\CafeteriaSettingPolicy;
use Modules\Cafeteria\Application\Policies\CafeteriaSpecialDayPolicy;
use Modules\Cafeteria\Application\Policies\CafeteriaSubsidyLedgerPolicy;
use Modules\Cafeteria\Application\Policies\CafeteriaSubsidyRulePolicy;
use Modules\Cafeteria\Application\Policies\CafeteriaTransactionPolicy;
use Modules\Cafeteria\Application\Policies\EmployeeCafeteriaExclusionPolicy;
use Modules\Cafeteria\Application\Queries\CafeteriaDirectoryQuery;
use Modules\Cafeteria\Application\Queries\CafeteriaStaffAssignmentsQuery;
use Modules\Cafeteria\Application\Queries\EmployeeCafeteriaActivityQuery;
use Modules\Cafeteria\Application\Services\CafeteriaTerminalScanService;
use Modules\Cafeteria\Application\Services\Portal\CafeteriaPortalSelector;
use Modules\Cafeteria\Contracts\CafeteriaDirectory;
use Modules\Cafeteria\Contracts\CafeteriaStaffAssignments;
use Modules\Cafeteria\Contracts\CafeteriaTerminalScan;
use Modules\Cafeteria\Contracts\EmployeeCafeteriaActivity;
use Modules\Cafeteria\Domain\Models\CafeteriaDayRule;
use Modules\Cafeteria\Domain\Models\CafeteriaFoodOrder;
use Modules\Cafeteria\Domain\Models\CafeteriaFoodOrderItem;
use Modules\Cafeteria\Domain\Models\CafeteriaMenu;
use Modules\Cafeteria\Domain\Models\CafeteriaMenuItem;
use Modules\Cafeteria\Domain\Models\CafeteriaProvider;
use Modules\Cafeteria\Domain\Models\CafeteriaProviderAssignment;
use Modules\Cafeteria\Domain\Models\CafeteriaProviderBranch;
use Modules\Cafeteria\Domain\Models\CafeteriaProviderLedgerEntry;
use Modules\Cafeteria\Domain\Models\CafeteriaProviderUser;
use Modules\Cafeteria\Domain\Models\CafeteriaReportRun;
use Modules\Cafeteria\Domain\Models\CafeteriaServiceAssignment;
use Modules\Cafeteria\Domain\Models\CafeteriaServiceNetwork;
use Modules\Cafeteria\Domain\Models\CafeteriaServicePolicy;
use Modules\Cafeteria\Domain\Models\CafeteriaSetting;
use Modules\Cafeteria\Domain\Models\CafeteriaSettlement;
use Modules\Cafeteria\Domain\Models\CafeteriaSettlementLine;
use Modules\Cafeteria\Domain\Models\CafeteriaSpecialDay;
use Modules\Cafeteria\Domain\Models\CafeteriaSubsidyLedger;
use Modules\Cafeteria\Domain\Models\CafeteriaSubsidyRule;
use Modules\Cafeteria\Domain\Models\CafeteriaTransaction;
use Modules\Cafeteria\Domain\Models\CafeteriaTransactionConsumedDay;
use Modules\Cafeteria\Domain\Models\EmployeeCafeteriaExclusion;
use Modules\Cafeteria\Domain\Models\OrganizationCafeteriaAccess;
use Modules\Cafeteria\Domain\Models\OrganizationCafeteriaLocationAccess;
use Modules\Cafeteria\Http\Middleware\EnsureCafeteriaPortalUser;
use Modules\Cafeteria\Http\Middleware\EnsureCafeteriaProviderAssigned;
use Modules\Cafeteria\Http\Middleware\EnsureProviderHasActiveAssignment;
use Modules\Cafeteria\Http\Middleware\SetCafeteriaPortalContext;
use Modules\Cafeteria\Infrastructure\Console\AuditCafeteriaConfiguration;
use Modules\Cafeteria\Infrastructure\Console\CafeteriaLegacyPolicyReport;
use Modules\Cafeteria\Infrastructure\Console\CafeteriaPlatformPing;
use Modules\Cafeteria\Infrastructure\Console\CafeteriaProviderUsersMigrateCommand;
use Modules\Cafeteria\Infrastructure\Console\LinkCafeteriaProvidersToServiceProviders;
use Modules\Cafeteria\Infrastructure\Console\SyncCafeteriaPolicyStatuses;
use Modules\Entitlements\Contracts\ReadModels\ServicePointRecord;
use Modules\Providers\Contracts\PortalLocationSource;
use Modules\Providers\Contracts\ReadModels\ProviderRecord;

/**
 * Everything the application needs to know about the Cafeteria module
 * (ADR 0017): the implementations of its public contracts, its policies,
 * console commands and middleware aliases, the relations it attaches to
 * Core and provider models, and the names its records keep in the database.
 * The web-group redirect to the cafeteria platform (ADR 0014) stays in
 * bootstrap/app.php, where its place in the middleware order is set.
 */
final class CafeteriaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EmployeeCafeteriaActivity::class, EmployeeCafeteriaActivityQuery::class);
        // Plugs the cafeteria locations into the provider portal's switcher.
        $this->app->bind(PortalLocationSource::class, CafeteriaPortalSelector::class);
        $this->app->bind(CafeteriaTerminalScan::class, CafeteriaTerminalScanService::class);
        $this->app->bind(CafeteriaDirectory::class, CafeteriaDirectoryQuery::class);
        $this->app->bind(CafeteriaStaffAssignments::class, CafeteriaStaffAssignmentsQuery::class);
    }

    public function boot(): void
    {
        // Moved models keep their old class names in the database (roles, audit logs, tokens).
        Relation::morphMap([
            'App\Models\CafeteriaDayRule' => CafeteriaDayRule::class,
            'App\Models\CafeteriaFoodOrder' => CafeteriaFoodOrder::class,
            'App\Models\CafeteriaFoodOrderItem' => CafeteriaFoodOrderItem::class,
            'App\Models\CafeteriaMenu' => CafeteriaMenu::class,
            'App\Models\CafeteriaMenuItem' => CafeteriaMenuItem::class,
            'App\Models\CafeteriaProvider' => CafeteriaProvider::class,
            'App\Models\CafeteriaProviderAssignment' => CafeteriaProviderAssignment::class,
            'App\Models\CafeteriaProviderBranch' => CafeteriaProviderBranch::class,
            'App\Models\CafeteriaProviderLedgerEntry' => CafeteriaProviderLedgerEntry::class,
            'App\Models\CafeteriaProviderUser' => CafeteriaProviderUser::class,
            'App\Models\CafeteriaReportRun' => CafeteriaReportRun::class,
            'App\Models\CafeteriaServiceAssignment' => CafeteriaServiceAssignment::class,
            'App\Models\CafeteriaServiceNetwork' => CafeteriaServiceNetwork::class,
            'App\Models\CafeteriaServicePolicy' => CafeteriaServicePolicy::class,
            'App\Models\CafeteriaSetting' => CafeteriaSetting::class,
            'App\Models\CafeteriaSettlement' => CafeteriaSettlement::class,
            'App\Models\CafeteriaSettlementLine' => CafeteriaSettlementLine::class,
            'App\Models\CafeteriaSpecialDay' => CafeteriaSpecialDay::class,
            'App\Models\CafeteriaSubsidyLedger' => CafeteriaSubsidyLedger::class,
            'App\Models\CafeteriaSubsidyRule' => CafeteriaSubsidyRule::class,
            'App\Models\CafeteriaTransaction' => CafeteriaTransaction::class,
            'App\Models\CafeteriaTransactionConsumedDay' => CafeteriaTransactionConsumedDay::class,
            'App\Models\EmployeeCafeteriaExclusion' => EmployeeCafeteriaExclusion::class,
            'App\Models\OrganizationCafeteriaAccess' => OrganizationCafeteriaAccess::class,
            'App\Models\OrganizationCafeteriaLocationAccess' => OrganizationCafeteriaLocationAccess::class,
        ]);

        // Policy discovery only looks in App\Policies.
        Gate::policy(CafeteriaDayRule::class, CafeteriaDayRulePolicy::class);
        Gate::policy(CafeteriaProvider::class, CafeteriaProviderPolicy::class);
        Gate::policy(CafeteriaProviderUser::class, CafeteriaProviderUserPolicy::class);
        Gate::policy(CafeteriaReportRun::class, CafeteriaReportRunPolicy::class);
        Gate::policy(CafeteriaSetting::class, CafeteriaSettingPolicy::class);
        Gate::policy(CafeteriaSpecialDay::class, CafeteriaSpecialDayPolicy::class);
        Gate::policy(CafeteriaSubsidyLedger::class, CafeteriaSubsidyLedgerPolicy::class);
        Gate::policy(CafeteriaSubsidyRule::class, CafeteriaSubsidyRulePolicy::class);
        Gate::policy(CafeteriaTransaction::class, CafeteriaTransactionPolicy::class);
        Gate::policy(EmployeeCafeteriaExclusion::class, EmployeeCafeteriaExclusionPolicy::class);

        // Cafeteria locations and staff assignments belong to Cafeteria, so Cafeteria
        // attaches these relations; the provider, terminal and user models know nothing of it.
        // Provider relations go on the read model the Providers module publishes.
        ProviderRecord::resolveRelationUsing('cafeteriaProvider', fn (ProviderRecord $provider): HasOne => $provider->hasOne(CafeteriaProvider::class, 'provider_id')
            // The provider's default location: its main cafeteria, else the oldest one.
            ->orderByRaw("case when location_type = 'main' then 0 else 1 end")
            ->oldest());
        ProviderRecord::resolveRelationUsing('cafeterias', fn (ProviderRecord $provider): HasMany => $provider->hasMany(CafeteriaProvider::class, 'provider_id'));
        ProviderRecord::resolveRelationUsing('cafeteriaNetworks', fn (ProviderRecord $provider): HasMany => $provider->hasMany(CafeteriaServiceNetwork::class, 'provider_id'));
        ServicePointRecord::resolveRelationUsing('cafeteriaDetails', fn (ServicePointRecord $provider): HasOne => $provider->hasOne(CafeteriaProvider::class, 'service_provider_id'));
        ServiceTerminal::resolveRelationUsing('cafeteriaProvider', fn (ServiceTerminal $terminal): BelongsTo => $terminal->belongsTo(CafeteriaProvider::class, 'cafeteria_provider_id'));
        User::resolveRelationUsing('cafeteriaProviders', fn (User $user): BelongsToMany => $user->belongsToMany(CafeteriaProvider::class, 'cafeteria_provider_assignments')
            ->withPivot(['role', 'provider_role', 'is_active', 'assigned_by', 'effective_from', 'effective_to'])
            ->withTimestamps());
        User::resolveRelationUsing('primaryCafeteriaProvider', fn (User $user): BelongsTo => $user->belongsTo(CafeteriaProvider::class, 'cafeteria_provider_id'));

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('provider.assigned', EnsureProviderHasActiveAssignment::class);
        $router->aliasMiddleware('cafeteria.portal', EnsureCafeteriaPortalUser::class);
        $router->aliasMiddleware('cafeteria.provider.assigned', EnsureCafeteriaProviderAssigned::class);
        $router->aliasMiddleware('cafeteria.portal.context', SetCafeteriaPortalContext::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                AuditCafeteriaConfiguration::class,
                CafeteriaLegacyPolicyReport::class,
                CafeteriaPlatformPing::class,
                CafeteriaProviderUsersMigrateCommand::class,
                LinkCafeteriaProvidersToServiceProviders::class,
                SyncCafeteriaPolicyStatuses::class,
            ]);
        }
    }
}
