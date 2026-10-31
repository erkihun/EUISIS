<?php

declare(strict_types=1);

namespace Modules\Entitlements\Providers;

use App\Backbone\Contracts\ProviderCodeLookup;
use App\Enums\CodeRuleEntityType;
use App\Services\CodeGeneration\CodeRuleTargetRegistry;
use App\Services\Organizations\OrganizationDeletionGuard;
use App\Services\RecycleBin\RecycleBinRegistry;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Entitlements\Application\Policies\EntitlementPolicy;
use Modules\Entitlements\Application\Policies\EntitlementRulePolicy;
use Modules\Entitlements\Application\Policies\ServiceProviderPolicy;
use Modules\Entitlements\Application\Policies\ServiceTransactionPolicy;
use Modules\Entitlements\Application\Policies\ServiceTypePolicy;
use Modules\Entitlements\Application\Services\CardServiceAuthorizationService;
use Modules\Entitlements\Application\Services\ServiceDeliveryRegistryService;
use Modules\Entitlements\Application\Services\ServiceProviderCodeLookup;
use Modules\Entitlements\Contracts\CardServiceAuthorization;
use Modules\Entitlements\Contracts\ReadModels\EntitlementRecord;
use Modules\Entitlements\Contracts\ServiceDeliveryRegistry;
use Modules\Entitlements\Domain\Models\Entitlement;
use Modules\Entitlements\Domain\Models\EntitlementRule;
use Modules\Entitlements\Domain\Models\ServiceProvider as ServiceProviderModel;
use Modules\Entitlements\Domain\Models\ServiceProviderUser;
use Modules\Entitlements\Domain\Models\ServiceTransaction;
use Modules\Entitlements\Domain\Models\ServiceType;

/** Composition root for service delivery and entitlement records. */
final class EntitlementsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProviderCodeLookup::class, ServiceProviderCodeLookup::class);
        $this->app->bind(ServiceDeliveryRegistry::class, ServiceDeliveryRegistryService::class);
        $this->app->bind(CardServiceAuthorization::class, CardServiceAuthorizationService::class);
    }

    public function boot(): void
    {
        Relation::morphMap([
            'App\\Models\\Entitlement' => Entitlement::class,
            'App\\Models\\EntitlementRule' => EntitlementRule::class,
            'App\\Models\\ServiceProvider' => ServiceProviderModel::class,
            'App\\Models\\ServiceProviderUser' => ServiceProviderUser::class,
            'App\\Models\\ServiceTransaction' => ServiceTransaction::class,
            'App\\Models\\ServiceType' => ServiceType::class,
        ]);

        Gate::policy(Entitlement::class, EntitlementPolicy::class);
        Gate::policy(EntitlementRecord::class, EntitlementPolicy::class);
        Gate::policy(EntitlementRule::class, EntitlementRulePolicy::class);
        Gate::policy(ServiceProviderModel::class, ServiceProviderPolicy::class);
        Gate::policy(ServiceTransaction::class, ServiceTransactionPolicy::class);
        Gate::policy(ServiceType::class, ServiceTypePolicy::class);

        OrganizationDeletionGuard::registerDependent('service_providers', 'organization_id');
        CodeRuleTargetRegistry::registerTarget(CodeRuleEntityType::ServiceProvider, [
            'model' => ServiceProviderModel::class,
            'table' => 'service_providers',
            'code_column' => 'code',
            'label' => 'Service Provider Code',
            'immutable_after_create' => false,
        ]);
        CodeRuleTargetRegistry::registerCollisionCheck(CodeRuleEntityType::ServiceProvider, ServiceProviderModel::class, 'code');
        CodeRuleTargetRegistry::registerTarget(CodeRuleEntityType::ServiceType, [
            'model' => ServiceType::class,
            'table' => 'service_types',
            'code_column' => 'code',
            'label' => 'Service Type Code',
            'immutable_after_create' => false,
        ]);
        CodeRuleTargetRegistry::registerCollisionCheck(CodeRuleEntityType::ServiceType, ServiceType::class, 'code', true);
        RecycleBinRegistry::registerType('service_types', [
            'model' => ServiceType::class,
            'label_key' => 'recycleBin.types.service_types',
            'restore_permission' => 'service-types.restore',
            'view_deleted_permission' => 'service-types.viewDeleted',
        ]);
        RecycleBinRegistry::registerType('entitlement_rules', [
            'model' => EntitlementRule::class,
            'label_key' => 'recycleBin.types.entitlement_rules',
            'restore_permission' => 'entitlement-rules.restore',
            'view_deleted_permission' => 'entitlement-rules.viewDeleted',
        ]);
    }
}
