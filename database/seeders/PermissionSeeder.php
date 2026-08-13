<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = require database_path('seeders/data/permissions.php');
        foreach ($catalog as $entry) {
            Permission::updateOrCreate(
                ['name' => $entry['name'], 'guard_name' => 'web'],
                array_diff_key($entry, ['name' => 1]),
            );
        }

        $legacy = [
            'dashboard.view', 'dashboard.reports',
            'organizations.view', 'organizations.manage', 'organizations.create',
            'organizations.update', 'organizations.delete', 'organizations.import',
            'employees.view', 'employees.manage',
            'cards.view', 'cards.manage',
            'entitlements.view', 'entitlements.manage',
            'transactions.view', 'transactions.manage',
            'audit.view', 'reports.view', 'reports.export',
            'organization-types.viewAny', 'organization-types.view', 'organization-types.create',
            'organization-types.update', 'organization-types.delete', 'organization-types.restore',
            'organization-types.viewDeleted',
            'organizations.viewAny',
            'hierarchy-versions.viewAny', 'hierarchy-versions.view', 'hierarchy-versions.create',
            'hierarchy-versions.update', 'hierarchy-versions.archive', 'hierarchy-versions.publish',
            'hierarchy-versions.manageTree',
            'organization-edges.view', 'organization-edges.create', 'organization-edges.update',
            'organization-edges.remove',
            'users.viewAny', 'users.view', 'users.create', 'users.update', 'users.delete',
            'users.archive', 'users.restore', 'users.assignRoles', 'users.resetPassword',
            'users.deactivate', 'users.updateProfilePhoto', 'users.viewSensitive',
            'users.assignOrganizationScopes',
            'user-organization-scopes.viewAny', 'user-organization-scopes.create',
            'user-organization-scopes.update', 'user-organization-scopes.delete',
            'user-organization-scopes.restore',
            'roles.viewAny', 'roles.view', 'roles.create', 'roles.update', 'roles.delete',
            'roles.assignPermissions',
            'permissions.viewAny', 'permissions.view',
            'system-settings.view', 'system-settings.update', 'system-settings.manageUi',
            'system-settings.manageGeneral', 'system-settings.manageLocalization',
            'system-settings.manageNotifications', 'system-settings.manageEmail',
            'system-settings.manageSms', 'system-settings.manageTelegram',
            'system-settings.manageSecurity', 'system-settings.manageAppearance',
            'system-settings.manageIdCards', 'system-settings.clearCache',
            'system-settings.testNotificationChannels', 'system-settings.uploadAssets',
            'id-cards.viewAny', 'id-cards.view', 'id-cards.create', 'id-cards.update',
            'id-cards.archive', 'id-cards.submitRequest', 'id-cards.verifyRequest',
            'id-cards.approveRequest', 'id-cards.rejectRequest', 'id-cards.createPrintBatch',
            'id-cards.print', 'id-cards.issue', 'id-cards.activate', 'id-cards.reportLost',
            'id-cards.reportDamaged', 'id-cards.replace', 'id-cards.revoke', 'id-cards.verify',
            'id-cards.export', 'id-cards.printAnytime', 'id-cards.exportPng', 'id-cards.previewSvg',
            'card-verifications.viewAny',
            'employees.viewAny', 'employees.viewPii', 'entitlements.viewAny', 'providers.viewAny',
            'service-transactions.viewAny', 'audit-logs.viewAny',
            'occupations.viewAny', 'occupations.view', 'occupations.create', 'occupations.update',
            'occupations.archive', 'occupations.delete', 'occupations.restore', 'occupations.export',
            'isic-activities.viewAny', 'isic-activities.view', 'isic-activities.create',
            'isic-activities.update', 'isic-activities.archive', 'isic-activities.delete',
            'isic-activities.restore', 'isic-activities.export',
            'positions.viewAny', 'positions.view', 'positions.create', 'positions.update',
            'positions.archive', 'positions.delete', 'positions.restore', 'positions.viewDeleted',
            'positions.export',
            'transfers.viewAny', 'transfers.view', 'transfers.create', 'transfers.update',
            'transfers.submit', 'transfers.confirmCurrentOrganization',
            'transfers.confirmReceivingOrganization', 'transfers.approve', 'transfers.reject',
            'transfers.cancel', 'transfers.complete', 'transfers.export',
            'service-types.viewAny', 'service-types.view', 'service-types.create',
            'service-types.update', 'service-types.archive', 'service-types.delete',
            'service-types.restore', 'service-types.viewDeleted', 'service-types.export',
            'entitlement-rules.viewAny', 'entitlement-rules.view', 'entitlement-rules.create',
            'entitlement-rules.update', 'entitlement-rules.archive', 'entitlement-rules.delete',
            'entitlement-rules.restore', 'entitlement-rules.viewDeleted', 'entitlement-rules.export',
            'code-rules.viewAny', 'code-rules.view', 'code-rules.create', 'code-rules.update',
            'code-rules.archive', 'code-rules.delete', 'code-rules.restore', 'code-rules.viewDeleted',
            'code-rules.preview', 'code-rules.generate', 'code-rules.export',
            'code-rules.manageOverrides', 'code-rules.viewSequences', 'code-rules.manageSequences',
            'code-rules.resetSequence',
            'organization-units.viewAny', 'organization-units.view', 'organization-units.create',
            'organization-units.update', 'organization-units.archive', 'organization-units.delete',
            'organization-units.restore', 'organization-units.viewDeleted',
            'organization-units.manageHierarchy', 'organization-units.export',
            'organization-unit-types.viewAny', 'organization-unit-types.view',
            'organization-unit-types.create', 'organization-unit-types.update',
            'organization-unit-types.archive', 'organization-unit-types.delete',
            'organization-unit-types.restore', 'organization-unit-types.viewDeleted',
            'recycle-bin.view', 'recycle-bin.restore', 'recycle-bin.viewDetails',
            'recycle-bin.forceDelete',

            // Position Establishments
            'position-establishments.viewAny', 'position-establishments.view',
            'position-establishments.create', 'position-establishments.update',
            'position-establishments.approve', 'position-establishments.archive',
            'position-establishments.restore',

            // Vacancy Announcements
            'vacancy-announcements.viewAny', 'vacancy-announcements.view',
            'vacancy-announcements.create', 'vacancy-announcements.update',
            'vacancy-announcements.publish', 'vacancy-announcements.close',
            'vacancy-announcements.delete',

            // Vacancy Applications
            'vacancy-applications.viewAny', 'vacancy-applications.view',
            'vacancy-applications.submit', 'vacancy-applications.withdraw',
            'vacancy-applications.screen', 'vacancy-applications.select',
            'vacancy-applications.reject', 'vacancy-applications.initiateTransfer',

            // Cafeteria Providers
            'cafeteria_providers.viewAny', 'cafeteria_providers.view',
            'cafeteria_providers.create', 'cafeteria_providers.update',
            'cafeteria_providers.archive', 'cafeteria_providers.restore',

            // Cafeteria Settings
            'cafeteria_settings.view', 'cafeteria_settings.update',

            // Cafeteria Transactions
            'cafeteria_transactions.view', 'cafeteria_transactions.scan',
            'cafeteria_transactions.reverse',

            // Cafeteria Reports
            'cafeteria_reports.view', 'cafeteria_reports.generate', 'cafeteria_reports.export',

            // Cafeteria Ledger
            'cafeteria_ledger.view',

            // Cafeteria Day Rules
            'cafeteria_day_rules.view', 'cafeteria_day_rules.update',

            // Cafeteria Holidays
            'cafeteria_holidays.create',

            // Cafeteria Subsidy Rules
            'cafeteria_subsidy_rules.view', 'cafeteria_subsidy_rules.create',
            'cafeteria_subsidy_rules.update', 'cafeteria_subsidy_rules.archive',

            // Cafeteria Special Days
            'cafeteria_special_days.view', 'cafeteria_special_days.create',
            'cafeteria_special_days.update', 'cafeteria_special_days.archive',

            // Cafeteria Employee Exclusions
            'cafeteria_employee_exclusions.view', 'cafeteria_employee_exclusions.create',
            'cafeteria_employee_exclusions.update', 'cafeteria_employee_exclusions.end',
            'cafeteria_employee_exclusions.archive',

            // Institution Offices
            'institution-offices.viewAny', 'institution-offices.view',
            'institution-offices.create', 'institution-offices.update',
            'institution-offices.delete', 'institution-offices.restore',
            'institution-offices.move', 'institution-offices.viewTree',
            'institution-offices.export',

            // Cafeteria Provider Portal
            'cafeteria-portal.login', 'cafeteria-portal.viewDashboard',
            'cafeteria-portal.scan', 'cafeteria-portal.viewTransactions',
            'cafeteria-portal.viewLedger', 'cafeteria-portal.manageMenus',
            'cafeteria-portal.manageOrders', 'cafeteria-portal.viewReports',
            'cafeteria-portal.updateProfile', 'cafeteria-portal.view',
            'cafeteria-portal.impersonate', 'cafeteria-portal.exportTransactions',
            'provider-cafeteria-transactions.export',
            'provider-cafeteria-payment-claims.export',

            // Grievance & Administrative Tribunal module
            'grievances.view', 'grievances.manage', 'grievances.committee',
            'grievances.chairperson', 'grievances.manager', 'grievances.tribunal',
        ];

        foreach ($legacy as $permission) {
            $legacyPermission = Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => 'web'],
            );

            $fallback = [];
            if (blank($legacyPermission->description_en) || blank($legacyPermission->description_am)) {
                $fallback['description_en'] = "Allows performing the {$permission} system operation.";
                $fallback['description_am'] = "{$permission} የተባለውን የሥርዓት ተግባር መፈጸም ያስችላል።";
            }

            // Keep stragglers out of the UI's "other" bucket by deriving the
            // group from the permission name prefix.
            if (blank($legacyPermission->group)) {
                $fallback['group'] = str($permission)->before('.')->toString();
            }

            if ($fallback !== []) {
                $legacyPermission->update($fallback);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command->info('Permissions seeded successfully.');
    }
}
