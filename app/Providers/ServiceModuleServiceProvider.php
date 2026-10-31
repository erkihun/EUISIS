<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Organizations\OrganizationDeletionGuard;
use Illuminate\Support\ServiceProvider;

/**
 * What the service modules contribute to the backbone
 * (docs/architecture/backbone-refactor-migration.md, Stage 5).
 *
 * The backbone defines registries and contracts; the modules that own the
 * data fill them here. That keeps every dependency pointing from the modules
 * to the backbone, never the other way: code-rule targets and collision
 * checks and the tables that block deleting an organization. Modules under
 * Modules/ fill them from their own providers.
 */
class ServiceModuleServiceProvider extends ServiceProvider
{
    /**
     * Tables that reference organizations, by owning module.
     *
     * @var array<string, list<string>>
     */
    private const ORGANIZATION_DEPENDENTS = [
        // Service delivery and providers
        'providers' => ['assigned_organization_id'],
        // Core transfer records (Transfer and Recruitment register their own tables)
        'employee_transfers' => ['from_organization_id', 'to_organization_id'],
        // Cafeteria
        'cafeteria_providers' => ['organization_id'],
        'cafeteria_subsidy_rules' => ['organization_id'],
        'cafeteria_report_runs' => ['organization_id'],
        'cafeteria_special_days' => ['organization_id'],
        'cafeteria_provider_branches' => ['organization_id'],
        'cafeteria_provider_users' => ['organization_id'],
        // Transport
        'transport_providers' => ['assigned_organization_id'],
        'transport_routes' => ['assigned_organization_id'],
        // Grievances
        'grievance_committees' => ['organization_id'],
        'grievances' => ['organization_id'],
        'grievance_sla_rules' => ['organization_id'],
        'grievance_case_stages' => ['organization_id'],
        'grievance_sla_profiles' => ['organization_id'],
        'grievance_approval_rules' => ['organization_id'],
        'grievance_letters' => ['organization_id'],
        'grievance_external_authorities' => ['organization_id'],
    ];

    public function boot(): void
    {
        foreach (self::ORGANIZATION_DEPENDENTS as $table => $columns) {
            OrganizationDeletionGuard::registerDependent($table, ...$columns);
        }
    }
}
