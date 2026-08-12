<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Organizations\PublishHierarchyVersionAction;
use App\Enums\AssignmentStatus;
use App\Enums\CardRequestStatus;
use App\Enums\CardRequestType;
use App\Enums\CardStatus;
use App\Enums\CodeRuleEntityType;
use App\Enums\CodeRuleResetFrequency;
use App\Enums\EmployeeStatus;
use App\Enums\EntitlementStatus;
use App\Enums\HierarchyVersionStatus;
use App\Enums\OrganizationRelationshipType;
use App\Enums\OrganizationScopeType;
use App\Enums\OrganizationStatus;
use App\Models\CardIssuance;
use App\Models\CardPrintBatch;
use App\Models\CardPrintBatchItem;
use App\Models\CardReplacement;
use App\Models\CardRequest;
use App\Models\CardVerification;
use App\Models\CodeRule;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeTransfer;
use App\Models\Entitlement;
use App\Models\HierarchyVersion;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationEdge;
use App\Models\OrganizationNameHistory;
use App\Models\OrganizationType;
use App\Models\Permission;
use App\Models\Position;
use App\Models\ServiceProvider;
use App\Models\ServiceTransaction;
use App\Models\ServiceType;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserOrganizationScope;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    public function run(PublishHierarchyVersionAction $publishHierarchyVersionAction): void
    {
        $this->ensureRequiredTablesExist();
        $this->purgeDemoData();

        $this->seedPermissionsAndRoles();
        $this->seedSystemSettings();
        $this->seedCodeRules();
        $this->call(OrganizationUnitTypeSeeder::class);
        $this->call(IsicActivitySeeder::class);
        $this->call(OccupationSeeder::class);

        $organizationTypes = collect([
            ['code' => 'city_government', 'prefix' => 'CITY', 'name_en' => 'City Government'],
            ['code' => 'mayor_office', 'prefix' => 'MAYOR', 'name_en' => 'Mayor Office'],
            ['code' => 'bureau', 'prefix' => 'BUR', 'name_en' => 'Bureau'],
            ['code' => 'authority', 'prefix' => 'AUTH', 'name_en' => 'Authority'],
            ['code' => 'commission', 'prefix' => 'COMM', 'name_en' => 'Commission'],
            ['code' => 'agency', 'prefix' => 'AGY', 'name_en' => 'Agency'],
            ['code' => 'sector', 'prefix' => 'SEC', 'name_en' => 'Sector'],
            ['code' => 'directorate', 'prefix' => 'DIR', 'name_en' => 'Directorate'],
            ['code' => 'sub_city', 'prefix' => 'SUB', 'name_en' => 'Sub-city'],
            ['code' => 'woreda', 'prefix' => 'WOR', 'name_en' => 'Woreda'],
            ['code' => 'branch', 'prefix' => 'BR', 'name_en' => 'Branch'],
            ['code' => 'pool', 'prefix' => 'POOL', 'name_en' => 'Pool'],
            ['code' => 'service_provider', 'prefix' => 'SP', 'name_en' => 'Service Provider'],
            ['code' => 'department', 'prefix' => 'DEPT', 'name_en' => 'Department'],
            ['code' => 'team', 'prefix' => 'TEAM', 'name_en' => 'Team'],
        ])->mapWithKeys(function (array $type): array {
            $organizationType = OrganizationType::query()->firstOrCreate(
                ['code' => $type['code']],
                $type + ['is_demo' => true],
            );

            if ($organizationType->prefix === null) {
                $organizationType->forceFill(['prefix' => $type['prefix']])->save();
            }

            return [$type['code'] => $organizationType->fresh()];
        });

        $root = Organization::query()->create([
            'organization_type_id' => $organizationTypes['city_government']->id,
            'code' => 'AA-ROOT',
            'name_en' => 'Addis Ababa City Administration',
            'status' => OrganizationStatus::Active,
            'effective_from' => now()->toDateString(),
            'is_demo' => true,
        ]);
        $this->createNameHistory($root);

        $version = HierarchyVersion::query()->create([
            'version_name' => 'v1-demo',
            'status' => HierarchyVersionStatus::Draft,
            'effective_from' => now()->toDateString(),
            'is_demo' => true,
        ]);

        $subCities = collect([
            'Lemi Kura',
            'Bole',
            'Yeka',
            'Nifas Silk Lafto',
            'Lideta',
            'Arada',
            'Akaki Kality',
            'Kirkos',
            'Addis Ketema',
            'Gulele',
            'Kolfe Keraniyo',
        ])->map(function (string $name, int $index) use ($organizationTypes, $root, $version): Organization {
            $organization = Organization::query()->create([
                'organization_type_id' => $organizationTypes['sub_city']->id,
                'code' => sprintf('SC-%02d', $index + 1),
                'name_en' => $name,
                'status' => OrganizationStatus::Active,
                'effective_from' => now()->toDateString(),
                'is_demo' => true,
            ]);
            $this->createNameHistory($organization);

            $this->attachEdge($version, $root, $organization);

            return $organization;
        });

        $publicServiceBureau = Organization::query()->create([
            'organization_type_id' => $organizationTypes['bureau']->id,
            'code' => 'BUR-PSHRDB',
            'name_en' => 'Public Service and Human Resource Development Bureau',
            'status' => OrganizationStatus::Active,
            'effective_from' => now()->toDateString(),
            'is_demo' => true,
        ]);
        $this->createNameHistory($publicServiceBureau);
        $this->attachEdge($version, $root, $publicServiceBureau);

        $woreda = Organization::query()->create([
            'organization_type_id' => $organizationTypes['woreda']->id,
            'code' => 'WRD-01-DEMO',
            'name_en' => 'Woreda 01 Demo',
            'status' => OrganizationStatus::Active,
            'effective_from' => now()->toDateString(),
            'is_demo' => true,
        ]);
        $this->createNameHistory($woreda);
        $this->attachEdge($version, $subCities->first(), $woreda);

        $serviceTypes = collect([
            ['code' => 'transport', 'name_en' => 'Transport'],
            ['code' => 'cafeteria', 'name_en' => 'Cafeteria'],
            ['code' => 'consumer_association', 'name_en' => 'Consumer Association'],
        ])->mapWithKeys(fn (array $serviceType) => [
            $serviceType['code'] => ServiceType::firstOrCreate(
                ['code' => $serviceType['code']],
                $serviceType,
            ),
        ]);

        $transportProvider = ServiceProvider::query()->create([
            'organization_id' => $root->id,
            'service_type_id' => $serviceTypes['transport']->id,
            'name' => 'AA Transport Demo Provider',
            'code' => 'SP-TRANSPORT-DEMO',
            'status' => 'active',
            'is_demo' => true,
        ]);

        $superAdmin = User::firstOrCreate(
            ['email' => 'super.admin@demo.local'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'status' => 'active',
                'is_demo' => true,
            ],
        );
        $superAdmin->assignRole('Super Admin');

        $cityAdmin = User::factory()->create([
            'name' => 'City Admin',
            'email' => 'city.admin@demo.local',
            'password' => Hash::make('password'),
            'default_organization_id' => $root->id,
            'is_demo' => true,
        ]);
        $cityAdmin->assignRole('City Admin');
        $publishHierarchyVersionAction->execute($version, $cityAdmin);

        $hrOfficer = User::factory()->create([
            'name' => 'HR Officer',
            'email' => 'hr.officer@demo.local',
            'password' => Hash::make('password'),
            'default_organization_id' => $publicServiceBureau->id,
            'is_demo' => true,
        ]);
        $hrOfficer->assignRole('HR Officer');

        $providerUser = User::factory()->create([
            'name' => 'Transport Provider User',
            'email' => 'provider.transport@demo.local',
            'password' => Hash::make('password'),
            'default_organization_id' => $root->id,
            'is_demo' => true,
        ]);
        $providerUser->assignRole('Service Provider User');

        UserOrganizationScope::query()->create([
            'user_id' => $cityAdmin->id,
            'organization_id' => $root->id,
            'scope_type' => OrganizationScopeType::Citywide,
            'effective_from' => now()->toDateString(),
        ]);

        UserOrganizationScope::query()->create([
            'user_id' => $hrOfficer->id,
            'organization_id' => $publicServiceBureau->id,
            'scope_type' => OrganizationScopeType::Subtree,
            'effective_from' => now()->toDateString(),
        ]);

        UserOrganizationScope::query()->create([
            'user_id' => $providerUser->id,
            'organization_id' => $root->id,
            'scope_type' => OrganizationScopeType::ServiceProvider,
            'service_provider_id' => $transportProvider->id,
            'service_type_id' => $serviceTypes['transport']->id,
            'effective_from' => now()->toDateString(),
        ]);

        $demoPosition = Position::query()->firstOrCreate(
            ['job_position_code' => 'DEMO-HR-001'],
            [
                'organization_id' => $publicServiceBureau->id,
                'title_en' => 'Human Resource Officer',
                'title_am' => 'የሰው ሀብት ባለሙያ',
                'description_en' => 'Demo HR position',
                'is_active' => true,
                'effective_from' => now()->toDateString(),
            ]
        );

        $employee = Employee::query()->create([
            'employee_number' => 'EMP-0001',
            'first_name' => 'Demo',
            'last_name' => 'Employee',
            'full_name' => 'Demo Employee',
            'phone' => '0911000000',
            'status' => EmployeeStatus::Active,
            'is_demo' => true,
        ]);

        $assignment = EmployeeAssignment::query()->create([
            'employee_id' => $employee->id,
            'organization_id' => $publicServiceBureau->id,
            'position_id' => $demoPosition->id,
            'hierarchy_version_id' => $version->id,
            'assignment_status' => AssignmentStatus::Active,
            'effective_from' => now()->toDateString(),
            'is_current' => true,
        ]);
        $employee->update(['current_assignment_id' => $assignment->id]);

        $cardRequest = CardRequest::query()->create([
            'employee_id' => $employee->id,
            'requested_by' => $hrOfficer->id,
            'approved_by' => $cityAdmin->id,
            'status' => CardRequestStatus::Approved,
            'submitted_at' => now(),
            'approved_at' => now(),
        ]);

        $card = IdCard::query()->create([
            'employee_id' => $employee->id,
            'card_request_id' => $cardRequest->id,
            'card_number' => 'AA-2026-DEMO01',
            'status' => CardStatus::Active,
            'token_hash' => Hash::make('demo-token'),
            'issued_at' => now(),
            'expires_at' => now()->addYear(),
            'is_current' => true,
        ]);

        Entitlement::query()->create([
            'employee_id' => $employee->id,
            'service_type_id' => $serviceTypes['transport']->id,
            'service_provider_id' => $transportProvider->id,
            'status' => EntitlementStatus::Active,
            'quota_limit' => 30,
            'quota_used' => 2,
            'effective_from' => now()->toDateString(),
        ]);

        // --- Demo Employee 2: card in pending_print (approved, ready for print batch) ---
        $employee2 = Employee::query()->create([
            'employee_number' => 'EMP-0002',
            'first_name' => 'Pending',
            'last_name' => 'Print',
            'full_name' => 'Pending Print Employee',
            'phone' => '0911000002',
            'status' => EmployeeStatus::Active,
            'is_demo' => true,
        ]);

        $assignment2 = EmployeeAssignment::query()->create([
            'employee_id' => $employee2->id,
            'organization_id' => $publicServiceBureau->id,
            'position_id' => $demoPosition->id,
            'hierarchy_version_id' => $version->id,
            'assignment_status' => AssignmentStatus::Active,
            'effective_from' => now()->toDateString(),
            'is_current' => true,
        ]);
        $employee2->update(['current_assignment_id' => $assignment2->id]);

        $cardRequest2 = CardRequest::query()->create([
            'employee_id' => $employee2->id,
            'requested_by' => $hrOfficer->id,
            'approved_by' => $cityAdmin->id,
            'request_type' => CardRequestType::New->value,
            'status' => CardRequestStatus::Approved,
            'submitted_at' => now()->subHours(2),
            'approved_at' => now()->subHour(),
        ]);

        IdCard::query()->create([
            'employee_id' => $employee2->id,
            'card_request_id' => $cardRequest2->id,
            'card_number' => 'AA-2026-DEMO02',
            'status' => CardStatus::PendingPrint,
            'token_hash' => hash('sha256', 'demo-token-2'),
            'token_version' => 1,
            'expires_at' => now()->addYears(2),
            'is_current' => true,
            'display_snapshot' => [
                'full_name' => $employee2->full_name,
                'employee_number' => $employee2->employee_number,
                'organization' => $publicServiceBureau->name_en,
            ],
        ]);

        // --- Demo Employee 3: card in printed status (ready for issuance) ---
        $employee3 = Employee::query()->create([
            'employee_number' => 'EMP-0003',
            'first_name' => 'Printed',
            'last_name' => 'Card',
            'full_name' => 'Printed Card Employee',
            'phone' => '0911000003',
            'status' => EmployeeStatus::Active,
            'is_demo' => true,
        ]);

        $assignment3 = EmployeeAssignment::query()->create([
            'employee_id' => $employee3->id,
            'organization_id' => $publicServiceBureau->id,
            'position_id' => $demoPosition->id,
            'hierarchy_version_id' => $version->id,
            'assignment_status' => AssignmentStatus::Active,
            'effective_from' => now()->toDateString(),
            'is_current' => true,
        ]);
        $employee3->update(['current_assignment_id' => $assignment3->id]);

        $cardRequest3 = CardRequest::query()->create([
            'employee_id' => $employee3->id,
            'requested_by' => $hrOfficer->id,
            'approved_by' => $cityAdmin->id,
            'request_type' => CardRequestType::New->value,
            'status' => CardRequestStatus::Approved,
            'submitted_at' => now()->subDays(2),
            'approved_at' => now()->subDays(2)->addHours(1),
        ]);

        $card3 = IdCard::query()->create([
            'employee_id' => $employee3->id,
            'card_request_id' => $cardRequest3->id,
            'card_number' => 'AA-2026-DEMO03',
            'status' => CardStatus::Printed,
            'token_hash' => hash('sha256', 'demo-token-3'),
            'token_version' => 1,
            'printed_at' => now()->subDay(),
            'expires_at' => now()->addYears(2),
            'is_current' => true,
            'display_snapshot' => [
                'full_name' => $employee3->full_name,
                'employee_number' => $employee3->employee_number,
                'organization' => $publicServiceBureau->name_en,
            ],
        ]);

        ServiceTransaction::query()->create([
            'employee_id' => $employee->id,
            'id_card_id' => $card->id,
            'service_type_id' => $serviceTypes['transport']->id,
            'service_provider_id' => $transportProvider->id,
            'status' => 'authorized',
            'occurred_at' => now(),
            'reference' => 'DEMO-TX-1',
            'amount' => 15,
            'metadata' => ['source' => 'demo-seeder'],
        ]);
    }

    private function purgeDemoData(): void
    {
        $demoUserIds = User::where('is_demo', true)->pluck('id');
        $demoOrgIds = Organization::where('is_demo', true)->pluck('id');
        $demoVersionIds = HierarchyVersion::where('is_demo', true)->pluck('id');
        $demoEmpIds = Employee::where('is_demo', true)->pluck('id');
        $demoCardIds = $demoEmpIds->isNotEmpty()
            ? IdCard::whereIn('employee_id', $demoEmpIds)->pluck('id')
            : collect();

        if ($demoEmpIds->isNotEmpty()) {
            EmployeeTransfer::whereIn('employee_id', $demoEmpIds)->delete();
            ServiceTransaction::whereIn('employee_id', $demoEmpIds)->delete();
            Entitlement::whereIn('employee_id', $demoEmpIds)->delete();
        }

        if ($demoCardIds->isNotEmpty()) {
            CardVerification::whereIn('id_card_id', $demoCardIds)->delete();
            CardIssuance::whereIn('id_card_id', $demoCardIds)->delete();
            CardReplacement::where(static function ($q) use ($demoCardIds): void {
                $q->whereIn('old_card_id', $demoCardIds)
                    ->orWhereIn('new_card_id', $demoCardIds);
            })->delete();
            CardPrintBatchItem::whereIn('id_card_id', $demoCardIds)->delete();
        }

        if ($demoUserIds->isNotEmpty()) {
            $replacementUserId = User::query()
                ->whereNotIn('id', $demoUserIds)
                ->value('id');

            CardPrintBatch::whereIn('created_by', $demoUserIds)->delete();
            UserOrganizationScope::whereIn('user_id', $demoUserIds)->delete();
            DB::table('audit_logs')->whereIn('actor_user_id', $demoUserIds)->delete();
            HierarchyVersion::whereIn('approved_by', $demoUserIds)->update([
                'approved_by' => null,
                'approval_date' => null,
            ]);

            if ($replacementUserId !== null) {
                CardRequest::whereIn('requested_by', $demoUserIds)->update(['requested_by' => $replacementUserId]);
            }

            CardRequest::whereIn('approved_by', $demoUserIds)->update(['approved_by' => null]);
            CardRequest::whereIn('rejected_by', $demoUserIds)->update(['rejected_by' => null]);
            CardRequest::whereIn('cancelled_by', $demoUserIds)->update(['cancelled_by' => null]);
            EmployeeTransfer::whereIn('current_org_confirmed_by', $demoUserIds)->update(['current_org_confirmed_by' => null]);
            EmployeeTransfer::whereIn('receiving_organization_confirmed_by', $demoUserIds)->update(['receiving_organization_confirmed_by' => null]);
            EmployeeTransfer::whereIn('approved_by', $demoUserIds)->update(['approved_by' => null]);
            EmployeeTransfer::whereIn('rejected_by', $demoUserIds)->update(['rejected_by' => null]);
            SystemSetting::whereIn('updated_by', $demoUserIds)->update(['updated_by' => null]);
        }

        if ($demoEmpIds->isNotEmpty()) {
            Employee::where('is_demo', true)->update(['current_assignment_id' => null]);
            IdCard::whereIn('employee_id', $demoEmpIds)->delete();
            CardRequest::whereIn('employee_id', $demoEmpIds)->delete();
            EmployeeAssignment::whereIn('employee_id', $demoEmpIds)->delete();
            Employee::where('is_demo', true)->delete();
        }

        Position::withTrashed()->where('job_position_code', 'like', 'DEMO-%')->forceDelete();
        ServiceProvider::where('is_demo', true)->delete();

        if ($demoUserIds->isNotEmpty()) {
            DB::table('code_generation_logs')->whereIn('generated_by', $demoUserIds)->update(['generated_by' => null]);
            DB::table('code_rules')->whereIn('created_by', $demoUserIds)->update(['created_by' => null]);
            DB::table('code_rules')->whereIn('updated_by', $demoUserIds)->update(['updated_by' => null]);
            DB::table('card_print_batches')->whereIn('created_by', $demoUserIds)->update(['created_by' => null]);
            DB::table('organization_unit_types')->whereIn('created_by', $demoUserIds)->update(['created_by' => null]);
            DB::table('organization_unit_types')->whereIn('updated_by', $demoUserIds)->update(['updated_by' => null]);
            DB::table('organization_units')->whereIn('created_by', $demoUserIds)->update(['created_by' => null]);
            DB::table('organization_units')->whereIn('updated_by', $demoUserIds)->update(['updated_by' => null]);
            User::where('is_demo', true)->each(static fn ($u) => $u->syncRoles([]));
            User::where('is_demo', true)->delete();
        }

        if ($demoVersionIds->isNotEmpty()) {
            DB::table('organization_closure_paths')
                ->whereIn('hierarchy_version_id', $demoVersionIds)->delete();
            OrganizationEdge::whereIn('hierarchy_version_id', $demoVersionIds)->delete();
        }
        if ($demoOrgIds->isNotEmpty()) {
            DB::table('organization_closure_paths')
                ->whereIn('ancestor_organization_id', $demoOrgIds)
                ->orWhereIn('descendant_organization_id', $demoOrgIds)
                ->delete();
            OrganizationEdge::whereIn('parent_organization_id', $demoOrgIds)
                ->orWhereIn('child_organization_id', $demoOrgIds)
                ->delete();
            OrganizationNameHistory::whereIn('organization_id', $demoOrgIds)->delete();
            DB::table('audit_logs')->whereIn('organization_id', $demoOrgIds)->delete();
            DB::table('user_organization_scopes')->whereIn('organization_id', $demoOrgIds)->delete();
            DB::table('organization_units')->whereIn('organization_id', $demoOrgIds)->delete();
            DB::table('service_providers')->whereIn('organization_id', $demoOrgIds)->delete();
            DB::table('organization_change_requests')->whereIn('organization_id', $demoOrgIds)->delete();
            Organization::whereIn('merged_into_id', $demoOrgIds)->update(['merged_into_id' => null]);
        }

        Organization::where('is_demo', true)->delete();
        HierarchyVersion::where('is_demo', true)->delete();
    }

    private function seedPermissionsAndRoles(): void
    {
        $permissions = [
            'dashboard.view',
            'dashboard.reports',
            // Legacy (kept for backward compatibility with existing tests/policies)
            'organizations.view',
            'organizations.manage',
            'organizations.import',
            'employees.view',
            'employees.manage',
            'cards.view',
            'cards.manage',
            'entitlements.view',
            'entitlements.manage',
            'transactions.view',
            'transactions.manage',
            'audit.view',
            'reports.view',
            'reports.export',

            // Organization Types
            'organization-types.viewAny',
            'organization-types.view',
            'organization-types.create',
            'organization-types.update',
            'organization-types.delete',
            'organization-types.restore',
            'organization-types.viewDeleted',
            'organizations.viewAny',
            'hierarchy-versions.viewAny',
            'hierarchy-versions.view',
            'hierarchy-versions.create',
            'hierarchy-versions.update',
            'hierarchy-versions.archive',
            'hierarchy-versions.publish',
            'hierarchy-versions.manageTree',
            'organization-edges.view',
            'organization-edges.create',
            'organization-edges.update',
            'organization-edges.remove',

            // Users
            'users.viewAny',
            'users.view',
            'users.create',
            'users.update',
            'users.delete',
            'users.archive',
            'users.restore',
            'users.assignRoles',
            'users.resetPassword',
            'users.deactivate',
            'users.updateProfilePhoto',
            'users.viewSensitive',
            'users.assignOrganizationScopes',

            // User Organization Scopes
            'user-organization-scopes.viewAny',
            'user-organization-scopes.create',
            'user-organization-scopes.update',
            'user-organization-scopes.delete',
            'user-organization-scopes.restore',

            // Roles
            'roles.viewAny',
            'roles.view',
            'roles.create',
            'roles.update',
            'roles.delete',
            'roles.assignPermissions',

            // Permissions
            'permissions.viewAny',
            'permissions.view',

            // System Settings
            'system-settings.view',
            'system-settings.update',
            'system-settings.manageUi',
            'system-settings.manageGeneral',
            'system-settings.manageLocalization',
            'system-settings.manageNotifications',
            'system-settings.manageEmail',
            'system-settings.manageSms',
            'system-settings.manageTelegram',
            'system-settings.manageSecurity',
            'system-settings.manageAppearance',
            'system-settings.manageIdCards',
            'system-settings.clearCache',
            'system-settings.testNotificationChannels',
            'system-settings.uploadAssets',

            // ID Cards (granular)
            'id-cards.viewAny',
            'id-cards.view',
            'id-cards.create',
            'id-cards.update',
            'id-cards.archive',
            'id-cards.submitRequest',
            'id-cards.verifyRequest',
            'id-cards.approveRequest',
            'id-cards.rejectRequest',
            'id-cards.createPrintBatch',
            'id-cards.print',
            'id-cards.issue',
            'id-cards.activate',
            'id-cards.reportLost',
            'id-cards.reportDamaged',
            'id-cards.replace',
            'id-cards.revoke',
            'id-cards.verify',
            'id-cards.export',
            'id-cards.printAnytime',
            'id-cards.exportPng',
            'id-cards.previewSvg',
            'card-verifications.viewAny',
            'employees.viewAny',
            'entitlements.viewAny',
            'providers.viewAny',
            'service-transactions.viewAny',
            'audit-logs.viewAny',
            'occupations.viewAny',
            'occupations.view',
            'occupations.create',
            'occupations.update',
            'occupations.archive',
            'occupations.delete',
            'occupations.restore',
            'occupations.export',
            'isic-activities.viewAny',
            'isic-activities.view',
            'isic-activities.create',
            'isic-activities.update',
            'isic-activities.archive',
            'isic-activities.delete',
            'isic-activities.restore',
            'isic-activities.export',
            'positions.viewAny',
            'positions.view',
            'positions.create',
            'positions.update',
            'positions.archive',
            'positions.delete',
            'positions.restore',
            'positions.viewDeleted',
            'positions.export',
            'transfers.viewAny',
            'transfers.view',
            'transfers.create',
            'transfers.update',
            'transfers.submit',
            'transfers.confirmCurrentOrganization',
            'transfers.confirmReceivingOrganization',
            'transfers.approve',
            'transfers.reject',
            'transfers.cancel',
            'transfers.complete',
            'transfers.export',
            'service-types.viewAny',
            'service-types.view',
            'service-types.create',
            'service-types.update',
            'service-types.archive',
            'service-types.delete',
            'service-types.restore',
            'service-types.viewDeleted',
            'service-types.export',
            'entitlement-rules.viewAny',
            'entitlement-rules.view',
            'entitlement-rules.create',
            'entitlement-rules.update',
            'entitlement-rules.archive',
            'entitlement-rules.delete',
            'entitlement-rules.restore',
            'entitlement-rules.viewDeleted',
            'entitlement-rules.export',
            'code-rules.viewAny',
            'code-rules.view',
            'code-rules.create',
            'code-rules.update',
            'code-rules.archive',
            'code-rules.delete',
            'code-rules.restore',
            'code-rules.viewDeleted',
            'code-rules.preview',
            'code-rules.generate',
            'code-rules.export',
            'code-rules.manageOverrides',
            'code-rules.viewSequences',
            'code-rules.manageSequences',
            'code-rules.resetSequence',

            'transport-providers.viewAny',
            'transport-providers.view',
            'transport-providers.create',
            'transport-providers.update',
            'transport-providers.delete',
            'transport-providers.restore',
            'transport-routes.viewAny',
            'transport-routes.view',
            'transport-routes.create',
            'transport-routes.update',
            'transport-routes.delete',
            'transport-vehicles.viewAny',
            'transport-vehicles.view',
            'transport-vehicles.create',
            'transport-vehicles.update',
            'transport-vehicles.delete',
            'transport-drivers.viewAny',
            'transport-drivers.view',
            'transport-drivers.create',
            'transport-drivers.update',
            'transport-drivers.delete',
            'transport-passes.viewAny',
            'transport-passes.view',
            'transport-passes.create',
            'transport-passes.update',
            'transport-passes.cancel',
            'transport-transactions.viewAny',
            'transport-transactions.view',
            'transport-transactions.export',
            'transport-reports.view',
            'transport-reports.export',
            'transport-settings.view',
            'transport-settings.update',

            // Organization Units
            'organization-units.viewAny',
            'organization-units.view',
            'organization-units.create',
            'organization-units.update',
            'organization-units.archive',
            'organization-units.delete',
            'organization-units.restore',
            'organization-units.viewDeleted',
            'organization-units.manageHierarchy',
            'organization-units.export',

            // Organization Unit Types
            'organization-unit-types.viewAny',
            'organization-unit-types.view',
            'organization-unit-types.create',
            'organization-unit-types.update',
            'organization-unit-types.archive',
            'organization-unit-types.delete',
            'organization-unit-types.restore',
            'organization-unit-types.viewDeleted',
            'recycle-bin.view',
            'recycle-bin.restore',
            'recycle-bin.viewDetails',
            'recycle-bin.forceDelete',
        ];

        $catalog = require database_path('seeders/data/permissions.php');
        foreach ($catalog as $entry) {
            Permission::updateOrCreate(
                ['name' => $entry['name'], 'guard_name' => 'web'],
                array_diff_key($entry, ['name' => 1]),
            );
        }

        // Ensure any permissions in the legacy list that are not yet in the catalog are also seeded.
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $allPermissions = Permission::all()->pluck('name')->toArray();

        $institutionAdminPerms = [
            'dashboard.view',
            'organizations.view', 'organizations.manage',
            'organizations.import',
            'organizations.viewAny',
            'organization-types.viewAny', 'organization-types.view',
            'hierarchy-versions.viewAny', 'hierarchy-versions.view',
            'organization-edges.view',
            'service-types.viewAny', 'service-types.view',
            'entitlement-rules.viewAny', 'entitlement-rules.view',
            'code-rules.viewAny', 'code-rules.view', 'code-rules.preview',
            'employees.view', 'employees.viewAny', 'employees.manage',
            'cards.view', 'cards.manage',
            'id-cards.viewAny',
            'audit.view', 'reports.view',
        ];

        $roleMap = [
            'Super Admin' => $allPermissions,
            'Public Service Bureau Admin' => $allPermissions,
            'City Admin' => $allPermissions,
            'Institution Admin' => $institutionAdminPerms,
            'Sub-city Admin' => ['dashboard.view', 'organizations.view', 'organizations.viewAny', 'organization-types.viewAny', 'service-types.viewAny', 'entitlement-rules.viewAny', 'employees.view', 'employees.viewAny', 'employees.manage', 'cards.view', 'id-cards.viewAny', 'audit.view', 'reports.view'],
            'Woreda Admin' => ['dashboard.view', 'organizations.view', 'organizations.viewAny', 'organization-types.viewAny', 'service-types.viewAny', 'entitlement-rules.viewAny', 'employees.view', 'employees.viewAny', 'employees.manage', 'cards.view', 'id-cards.viewAny', 'reports.view'],
            'HR Officer' => [
                'dashboard.view',
                'employees.view', 'employees.manage',
                'employees.viewAny',
                'cards.view', 'cards.manage',
                'id-cards.viewAny', 'id-cards.view', 'id-cards.submitRequest', 'id-cards.verifyRequest',
                'id-cards.printAnytime', 'id-cards.exportPng', 'id-cards.previewSvg',
                'entitlements.view', 'entitlements.viewAny',
                'service-types.viewAny', 'service-types.view',
                'entitlement-rules.viewAny', 'entitlement-rules.view',
                'occupations.viewAny', 'occupations.view', 'occupations.create', 'occupations.update',
                'isic-activities.viewAny', 'isic-activities.view',
                'positions.viewAny', 'positions.view', 'positions.create', 'positions.update',
                'transfers.viewAny', 'transfers.view', 'transfers.create', 'transfers.update', 'transfers.submit',
            ],
            'ID Card Officer' => [
                'dashboard.view',
                'cards.view', 'cards.manage',
                'id-cards.viewAny', 'id-cards.view', 'id-cards.create', 'id-cards.update',
                'service-types.viewAny', 'service-types.view',
                'id-cards.submitRequest', 'id-cards.verifyRequest', 'id-cards.approveRequest', 'id-cards.rejectRequest',
                'id-cards.createPrintBatch', 'id-cards.print', 'id-cards.issue', 'id-cards.activate',
                'id-cards.reportLost', 'id-cards.reportDamaged', 'id-cards.replace', 'id-cards.revoke',
                'card-verifications.viewAny',
                'id-cards.export', 'id-cards.printAnytime', 'id-cards.exportPng', 'id-cards.previewSvg',
            ],
            'Service Provider User' => ['dashboard.view', 'transactions.manage', 'service-transactions.viewAny', 'providers.viewAny', 'service-types.viewAny', 'service-types.view'],
            'Settlement Officer' => ['dashboard.view', 'transactions.view', 'service-transactions.viewAny', 'providers.viewAny', 'reports.view'],
            'Auditor' => ['dashboard.view', 'audit.view', 'audit-logs.viewAny', 'reports.view', 'occupations.viewAny', 'occupations.view', 'positions.viewAny', 'positions.view', 'transfers.viewAny', 'transfers.view', 'card-verifications.viewAny', 'service-types.viewAny', 'entitlement-rules.viewAny', 'hierarchy-versions.viewAny', 'hierarchy-versions.view', 'organization-edges.view', 'code-rules.viewAny', 'code-rules.view', 'code-rules.preview'],
            'Report Viewer' => ['dashboard.view', 'reports.view', 'dashboard.reports'],
        ];

        foreach ($roleMap as $role => $grantedPermissions) {
            $roleModel = Role::findOrCreate($role, 'web');
            $roleModel->syncPermissions($grantedPermissions);
        }
    }

    private function seedSystemSettings(): void
    {
        // Seed every setting declared in the registry idempotently.
        (new SystemSettingsSeeder)->run();

        // Legacy/branding/ui defaults (kept for backward compatibility).
        $defaults = [
            // Branding
            ['group' => 'branding', 'key' => 'app_name_en',   'value' => 'EUISIS',                                           'type' => 'string',  'label_en' => 'Application Name (English)',  'label_am' => 'የስርዓቱ ስም (እንግሊዝኛ)',  'is_public' => true,  'sort_order' => 1],
            ['group' => 'branding', 'key' => 'app_name_am',   'value' => 'ኢዩሲሲስ',                                             'type' => 'string',  'label_en' => 'Application Name (Amharic)', 'label_am' => 'የስርዓቱ ስም (አማርኛ)',    'is_public' => true,  'sort_order' => 2],
            ['group' => 'branding', 'key' => 'org_name_en',   'value' => 'Addis Ababa City Administration',                   'type' => 'string',  'label_en' => 'Organization Name (English)', 'label_am' => 'የድርጅቱ ስም (እንግሊዝኛ)', 'is_public' => true,  'sort_order' => 3],
            ['group' => 'branding', 'key' => 'org_name_am',   'value' => 'የአዲስ አበባ ከተማ አስተዳደር',                               'type' => 'string',  'label_en' => 'Organization Name (Amharic)', 'label_am' => 'የድርጅቱ ስም (አማርኛ)',   'is_public' => true,  'sort_order' => 4],

            // UI
            ['group' => 'ui', 'key' => 'default_theme',          'value' => 'light',    'type' => 'select',   'label_en' => 'Default Theme',             'is_public' => true,  'sort_order' => 1],
            ['group' => 'ui', 'key' => 'sidebar_collapsed',      'value' => 'false',    'type' => 'boolean',  'label_en' => 'Sidebar Collapsed by Default', 'is_public' => true, 'sort_order' => 2],
            ['group' => 'ui', 'key' => 'show_language_switcher', 'value' => 'true',     'type' => 'boolean',  'label_en' => 'Show Language Switcher',     'is_public' => true,  'sort_order' => 3],
            ['group' => 'ui', 'key' => 'table_page_size',        'value' => '25',       'type' => 'integer',  'label_en' => 'Default Table Page Size',    'is_public' => true,  'sort_order' => 4],
            ['group' => 'ui', 'key' => 'enable_animations',      'value' => 'true',     'type' => 'boolean',  'label_en' => 'Enable Animations',          'is_public' => true,  'sort_order' => 5],

            // Localization
            ['group' => 'localization', 'key' => 'default_locale',  'value' => 'en',        'type' => 'select',  'label_en' => 'Default Locale',    'is_public' => true,  'sort_order' => 1],
            ['group' => 'localization', 'key' => 'enabled_locales', 'value' => 'en,am',     'type' => 'string',  'label_en' => 'Enabled Locales',   'is_public' => true,  'sort_order' => 2],
            ['group' => 'localization', 'key' => 'date_format',     'value' => 'YYYY-MM-DD', 'type' => 'string',  'label_en' => 'Date Format',       'is_public' => true,  'sort_order' => 3],
            ['group' => 'localization', 'key' => 'timezone',        'value' => 'Africa/Addis_Ababa', 'type' => 'string', 'label_en' => 'Timezone', 'is_public' => false, 'sort_order' => 4],

            // Security
            ['group' => 'security', 'key' => 'session_timeout_minutes', 'value' => '120',  'type' => 'integer', 'label_en' => 'Session Timeout (minutes)',   'is_public' => false, 'sort_order' => 1],
            ['group' => 'security', 'key' => 'max_login_attempts',      'value' => '5',    'type' => 'integer', 'label_en' => 'Max Login Attempts',          'is_public' => false, 'sort_order' => 2],
            ['group' => 'security', 'key' => 'require_mfa_for_admins',  'value' => 'false', 'type' => 'boolean', 'label_en' => 'Require MFA for Admins (Legacy)', 'is_public' => false, 'sort_order' => 3],
            ['group' => 'security', 'key' => 'mfa_enabled',             'value' => 'true',  'type' => 'boolean', 'label_en' => 'Enable MFA',                  'is_public' => false, 'sort_order' => 4],
            ['group' => 'security', 'key' => 'mfa_required_for_all',    'value' => 'false', 'type' => 'boolean', 'label_en' => 'Require MFA for All Users',   'is_public' => false, 'sort_order' => 5],
            ['group' => 'security', 'key' => 'mfa_required_role_ids',   'value' => '[]',    'type' => 'multiselect', 'label_en' => 'Require MFA for Selected Roles', 'is_public' => false, 'sort_order' => 6],
        ];

        foreach ($defaults as $row) {
            SystemSetting::query()->firstOrCreate(
                ['group' => $row['group'], 'key' => $row['key']],
                array_merge(['id' => (string) Str::uuid(), 'is_encrypted' => false], $row),
            );
        }
    }

    private function ensureRequiredTablesExist(): void
    {
        $requiredTables = [
            'users',
            'permissions',
            'roles',
            'model_has_roles',
            'organizations',
            'hierarchy_versions',
            'employees',
            'employee_assignments',
            'card_requests',
            'id_cards',
            'service_types',
            'service_providers',
            'entitlements',
            'system_settings',
            'code_rules',
            'organization_unit_types',
        ];

        $missingTables = collect($requiredTables)
            ->filter(fn (string $table): bool => ! Schema::hasTable($table))
            ->values();

        if ($missingTables->isNotEmpty()) {
            throw new RuntimeException(
                'DatabaseSeeder requires migrated tables. Missing: '.$missingTables->implode(', ')
                .'. Run "php artisan migrate --seed" for first-time setup, or "php artisan migrate:fresh --seed" for a local reset.'
            );
        }
    }

    private function attachEdge(HierarchyVersion $version, Organization $parent, Organization $child): void
    {
        OrganizationEdge::query()->create([
            'hierarchy_version_id' => $version->id,
            'parent_organization_id' => $parent->id,
            'child_organization_id' => $child->id,
            'relationship_type' => OrganizationRelationshipType::ReportsTo,
            'effective_from' => now()->toDateString(),
        ]);
    }

    private function createNameHistory(Organization $organization): void
    {
        OrganizationNameHistory::query()->create([
            'organization_id' => $organization->id,
            'name_en' => $organization->name_en,
            'name_am' => $organization->name_am,
            'effective_from' => $organization->effective_from ?? now()->toDateString(),
        ]);
    }

    private function seedCodeRules(): void
    {
        $defaults = [
            [
                'entity_type' => CodeRuleEntityType::Organization->value,
                'scope_type' => null,
                'scope_id' => null,
                'name_en' => 'Organization Code',
                'name_am' => 'የድርጅት ኮድ',
                'prefix' => 'ORG',
                'format' => '{PREFIX}-{YEAR}-{SEQUENCE}',
                'sequence_length' => 5,
            ],
            [
                'entity_type' => CodeRuleEntityType::OrganizationType->value,
                'scope_type' => null,
                'scope_id' => null,
                'name_en' => 'Organization Type Code',
                'name_am' => 'የድርጅት አይነት ኮድ',
                'prefix' => 'OT',
                'format' => '{PREFIX}-{SEQUENCE}',
                'sequence_length' => 4,
            ],
            [
                'entity_type' => CodeRuleEntityType::Employee->value,
                'scope_type' => null,
                'scope_id' => null,
                'name_en' => 'Employee Number',
                'name_am' => 'የሰራተኛ ቁጥር',
                'prefix' => 'EMP',
                'format' => '{PREFIX}-{YEAR}-{SEQUENCE}',
                'sequence_length' => 6,
            ],
            [
                'entity_type' => CodeRuleEntityType::Position->value,
                'scope_type' => null,
                'scope_id' => null,
                'name_en' => 'Job Position Code',
                'name_am' => 'የስራ መደብ ኮድ',
                'prefix' => 'POS',
                // ownerCode/sequence, or ownerCode/hostCode/sequence when the
                // unit operates inside a host organization. The empty host
                // segment collapses because the separator is '/'.
                'format' => '{OWNER_ORG_CODE}/{HOST_ORG_CODE}/{SEQUENCE}',
                'separator' => '/',
                'sequence_length' => 2,
            ],
            [
                'entity_type' => CodeRuleEntityType::IdCard->value,
                'scope_type' => null,
                'scope_id' => null,
                'name_en' => 'ID Card Number',
                'name_am' => 'የመታወቂያ ካርድ ቁጥር',
                'prefix' => 'IDC',
                'format' => '{PREFIX}-{YEAR}-{SEQUENCE}',
                'sequence_length' => 6,
            ],
            [
                'entity_type' => CodeRuleEntityType::ServiceType->value,
                'scope_type' => null,
                'scope_id' => null,
                'name_en' => 'Service Type Code',
                'name_am' => 'የአገልግሎት አይነት ኮድ',
                'prefix' => 'SVT',
                'format' => '{PREFIX}-{SEQUENCE}',
                'sequence_length' => 4,
            ],
            [
                'entity_type' => CodeRuleEntityType::ServiceProvider->value,
                'scope_type' => null,
                'scope_id' => null,
                'name_en' => 'Service Provider Code',
                'name_am' => 'የአገልግሎት አቅራቢ ኮድ',
                'prefix' => 'SPR',
                'format' => '{PREFIX}-{SEQUENCE}',
                'sequence_length' => 4,
            ],
            [
                'entity_type' => CodeRuleEntityType::EntitlementRule->value,
                'scope_type' => null,
                'scope_id' => null,
                'name_en' => 'Entitlement Rule Code',
                'name_am' => 'የመብት ደንብ ኮድ',
                'prefix' => 'ETR',
                'format' => '{PREFIX}-{SEQUENCE}',
                'sequence_length' => 4,
            ],
            [
                'entity_type' => CodeRuleEntityType::OrganizationUnit->value,
                'scope_type' => null,
                'scope_id' => null,
                'name_en' => 'Organization Unit Code',
                'name_am' => 'የድርጅት ዩኒት ኮድ',
                'prefix' => 'UNIT',
                'format' => '{PREFIX}-{SEQUENCE}',
                'sequence_length' => 4,
            ],
            [
                'entity_type' => CodeRuleEntityType::OrganizationUnitType->value,
                'scope_type' => null,
                'scope_id' => null,
                'name_en' => 'Organization Unit Type Code',
                'name_am' => 'የድርጅት ዩኒት አይነት ኮድ',
                'prefix' => 'UTYPE',
                'format' => '{PREFIX}-{SEQUENCE}',
                'sequence_length' => 3,
            ],
            [
                'entity_type' => CodeRuleEntityType::CardRequest->value,
                'scope_type' => null,
                'scope_id' => null,
                'name_en' => 'Card Request Number',
                'name_am' => 'የካርድ ጥያቄ ቁጥር',
                'prefix' => 'CRQ',
                'format' => '{PREFIX}-{YEAR}-{SEQUENCE}',
                'sequence_length' => 5,
            ],
            [
                'entity_type' => CodeRuleEntityType::Occupation->value,
                'scope_type' => null,
                'scope_id' => null,
                'name_en' => 'Occupation Code',
                'name_am' => 'የሙያ ኮድ',
                'prefix' => 'OCC',
                'format' => '{PREFIX}-{SEQUENCE}',
                'sequence_length' => 4,
            ],
        ];

        foreach ($defaults as $default) {
            CodeRule::query()->firstOrCreate(
                [
                    'entity_type' => $default['entity_type'],
                    'scope_type' => $default['scope_type'],
                    'scope_id' => $default['scope_id'],
                    'name_en' => $default['name_en'],
                ],
                [
                    ...$default,
                    'active_scope_key' => CodeRule::buildActiveScopeKey(
                        $default['entity_type'],
                        $default['scope_type'],
                        $default['scope_id'],
                    ),
                    'suffix' => null,
                    'separator' => $default['separator'] ?? '-',
                    'next_number' => 1,
                    'reset_frequency' => CodeRuleResetFrequency::Never,
                    'year_format' => 'Y',
                    'is_active' => true,
                    'allow_manual_override' => false,
                    'require_approval_for_override' => true,
                ],
            );
        }
    }
}
