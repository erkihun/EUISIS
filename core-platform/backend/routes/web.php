<?php

use App\Actions\ManageDataQualityIssues;
use App\Actions\ManageHierarchyVersion;
use App\Http\Controllers\Web\CardController;
use App\Http\Controllers\Web\CardTemplateController;
use App\Http\Controllers\Web\CoreController;
use App\Http\Controllers\Web\DataQualityController;
use App\Http\Controllers\Web\DuplicateReviewController;
use App\Http\Controllers\Web\EmployeeController;
use App\Http\Controllers\Web\EmployeeEvidenceController;
use App\Http\Controllers\Web\HierarchyController;
use App\Http\Controllers\Web\HierarchyVersionController;
use App\Http\Controllers\Web\IdCardExportController;
use App\Http\Controllers\Web\IdCardTemplateController;
use App\Http\Controllers\Web\IdCheckerController;
use App\Http\Controllers\Web\ImportController;
use App\Http\Controllers\Web\IntegrationCenterController;
use App\Http\Controllers\Web\JobMasterController;
use App\Http\Controllers\Web\MfaController;
use App\Http\Controllers\Web\NotificationController;
use App\Http\Controllers\Web\OrganizationController;
use App\Http\Controllers\Web\OrganizationUnitController;
use App\Http\Controllers\Web\PositionController;
use App\Http\Controllers\Web\PrintBatchController;
use App\Http\Controllers\Web\ReportController;
use App\Http\Controllers\Web\RoleController;
use App\Http\Controllers\Web\SecurityCenterController;
use App\Http\Controllers\Web\SessionController;
use App\Http\Controllers\Web\SettingsController;
use App\Http\Controllers\Web\StructureTypeController;
use App\Http\Controllers\Web\SystemHealthController;
use App\Http\Controllers\Web\UserController;
use Illuminate\Support\Facades\Route;

/*
 * The EUISIS screens, served by the Core. Route names, paths and verbs are
 * the EUISIS ones, so the copied pages (core-platform/frontend) link and post
 * exactly as they do in EUISIS. Permission names are the EUISIS ones too,
 * granted by the Core role (App\Support\CorePermissions).
 */

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:20,1');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
    // Multi-factor authentication (ADR 0008), the EUISIS routes; RequireMfa sends privileged accounts here first.
    Route::get('/mfa/setup', [MfaController::class, 'showSetup'])->name('mfa.setup');
    Route::post('/mfa/setup/confirm', [MfaController::class, 'confirmSetup'])->middleware('throttle:6,1')->name('mfa.setup.confirm');
    Route::get('/mfa/challenge', [MfaController::class, 'showChallenge'])->name('mfa.challenge');
    Route::post('/mfa/challenge', [MfaController::class, 'verifyChallenge'])->middleware('throttle:6,1')->name('mfa.challenge.verify');
    Route::post('/mfa/disable', [MfaController::class, 'disable'])->middleware('throttle:6,1')->name('mfa.disable');
    Route::get('/change-password', [SessionController::class, 'forcedPassword'])->name('password.forced');
    Route::post('/change-password', [SessionController::class, 'updateForcedPassword'])->middleware('throttle:6,1')->name('password.forced.update');
    Route::post('/session/activity', [SessionController::class, 'activity'])->name('session.activity');
    Route::get('/session/status', [SessionController::class, 'status'])->name('session.status');
    Route::get('/notifications/feed', [SessionController::class, 'notifications'])->name('notifications.feed');
    Route::post('/notifications/read-all', [SessionController::class, 'readNotifications'])->name('notifications.read-all');
    Route::post('/notifications/{notification}/read', [SessionController::class, 'readNotifications'])->whereUuid('notification')->name('notifications.read');

    Route::get('/profile', [SessionController::class, 'profile'])->name('profile.edit');
    Route::patch('/profile', [SessionController::class, 'updateProfile'])->name('profile.update');
    Route::put('/password', [SessionController::class, 'updatePassword'])->middleware('throttle:6,1')->name('password.update');
    Route::delete('/profile/devices', [SessionController::class, 'destroyOtherDevices'])->name('profile.devices.destroy-others');
    Route::delete('/profile/devices/{session}', [SessionController::class, 'destroyDevice'])->name('profile.devices.destroy');

    Route::get('/', fn () => redirect()->route('core.dashboard'));
    Route::get('/dashboard', fn () => redirect()->route('core.dashboard'))->name('dashboard');

    Route::prefix('core')->name('core.')->group(function (): void {
        Route::get('/', [CoreController::class, 'dashboard'])->middleware('can:core.access')->name('dashboard');
        Route::get('/assignments', [CoreController::class, 'assignments'])->middleware('can:employees.view')->name('assignments');
        Route::get('/audit', [CoreController::class, 'auditCenter'])->middleware('can:audit.view')->name('audit');
        // System Health (ADR 0011): city-wide system_health.view; failed jobs are retried or removed with queue.manage.
        Route::get('/system-health', [SystemHealthController::class, 'show'])->name('system-health');
        Route::post('/system-health/jobs/{uuid}/retry', [SystemHealthController::class, 'retry'])->whereUuid('uuid')->middleware('throttle:30,1')->name('system-health.jobs.retry');
        Route::post('/system-health/jobs/{uuid}/forget', [SystemHealthController::class, 'forget'])->whereUuid('uuid')->middleware('throttle:30,1')->name('system-health.jobs.forget');

        // A person's own notifications (ADR 0011).
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
        Route::post('/notifications/read', [NotificationController::class, 'read'])->name('notifications.read');

        // Core reports and exports (ADR 0011): ReportCatalog limits every report to the reader's scope; ManageExports queues and audits.
        Route::middleware('can:reports.view')->group(function (): void {
            Route::get('/reports', [ReportController::class, 'index'])->name('reports');
            Route::post('/reports/{report}/export', [ReportController::class, 'export'])->middleware('throttle:20,10')->where('report', '[a-z_]+')->name('reports.export');
            Route::get('/reports/exports/{exportRun}', [ReportController::class, 'download'])->middleware('throttle:60,1')->name('reports.download');
        });

        // Data-quality issues (ADR 0011): scoped list and workflow; the scan itself runs hourly or on request.
        Route::middleware('can:data_quality.view')->group(function (): void {
            Route::get('/data-quality', [DataQualityController::class, 'index'])->name('data-quality');
            Route::post('/data-quality/issues/{issue}/{step}', [DataQualityController::class, 'step'])->whereIn('step', ManageDataQualityIssues::STEPS)->name('data-quality.step');
            Route::post('/data-quality/scan', [DataQualityController::class, 'scan'])->middleware('throttle:3,10')->name('data-quality.scan');
        });
        // The Core settings entry is the EUISIS System Settings page itself (as /core/card-templates is the
        // designer); a section in the address is carried through, the General tab otherwise.
        Route::get('/settings', fn () => redirect()->route('system-settings.index', ['tab' => request()->query('tab', 'general')]))
            ->middleware('can:system-settings.view')->name('settings');
        Route::post('/exit', [SessionController::class, 'exitCore'])->name('exit');

        // The duplicate review queue (ADR 0009); the review binding is scoped by the flagged employee.
        // Business imports (ADR 0009): ManageImports and ImportBatchPolicy decide each step.
        Route::middleware('can:imports.view')->prefix('imports')->name('imports')->group(function (): void {
            Route::get('/', [ImportController::class, 'index']);
            Route::post('/', [ImportController::class, 'store'])->middleware('throttle:20,1')->name('.store');
            Route::get('/template/{type}', [ImportController::class, 'template'])->name('.template');
            Route::get('/{importBatch}', [ImportController::class, 'show'])->name('.show');
            Route::post('/{importBatch}/approve', [ImportController::class, 'approve'])->name('.approve');
            Route::post('/{importBatch}/cancel', [ImportController::class, 'cancel'])->name('.cancel');
            Route::get('/{importBatch}/errors', [ImportController::class, 'errors'])->name('.errors');
        });

        // Job master data (ADR 0010): readers of positions see it; positions.master.manage changes it (ManageJobMasters).
        Route::middleware('can:positions.viewAny')->group(function (): void {
            Route::get('/job-masters', [JobMasterController::class, 'index'])->name('job-masters');
            Route::post('/job-masters/{kind}', [JobMasterController::class, 'store'])->middleware('can:positions.master.manage')->name('job-masters.store');
            Route::patch('/job-masters/{kind}/{id}', [JobMasterController::class, 'update'])->middleware('can:positions.master.manage')->whereUuid('id')->name('job-masters.update');
        });

        // The Security Center (ADR 0010): break-glass access (ManageBreakGlass decides) and the audit trail's integrity.
        Route::middleware('can:core.access')->prefix('security')->name('security')->group(function (): void {
            Route::get('/', [SecurityCenterController::class, 'show']);
            Route::post('/break-glass', [SecurityCenterController::class, 'store'])->middleware('throttle:10,1')->name('.break-glass.store');
            Route::post('/break-glass/{grant}/decide', [SecurityCenterController::class, 'decide'])->name('.break-glass.decide');
            Route::post('/break-glass/{grant}/end', [SecurityCenterController::class, 'end'])->name('.break-glass.end');
            Route::post('/audit/verify', [SecurityCenterController::class, 'verifyAudit'])->middleware('throttle:3,10')->name('.audit.verify');
        });

        // The print record of card templates (ADR 0010): versions a batch prints with, kept in step with the
        // EUISIS designer by CardTemplateMirror. The page itself is the EUISIS designer (id-card-templates.index).
        Route::middleware('can:card_templates.view')->prefix('card-templates')->name('card-templates')->group(function (): void {
            Route::get('/', fn () => redirect()->route('id-card-templates.index'));
            Route::post('/', [CardTemplateController::class, 'store'])->name('.store');
            Route::post('/{cardTemplate}/versions', [CardTemplateController::class, 'newVersion'])->name('.versions.store');
            Route::put('/versions/{version}', [CardTemplateController::class, 'update'])->name('.versions.update');
            Route::post('/versions/{version}/activate', [CardTemplateController::class, 'activate'])->name('.versions.activate');
            Route::post('/versions/{version}/retire', [CardTemplateController::class, 'retire'])->name('.versions.retire');
            Route::get('/versions/{version}/preview', [CardTemplateController::class, 'preview'])->middleware('throttle:60,1')->name('.versions.preview');
        });

        Route::middleware('can:employees.duplicates.review')->group(function (): void {
            Route::get('/duplicates', [DuplicateReviewController::class, 'index'])->name('duplicates');
            Route::post('/duplicates/{duplicateReview}/decide', [DuplicateReviewController::class, 'decide'])->name('duplicates.decide');
        });

        // The Integration Center: city-wide administrators only (CorePermissions CITY_ADMIN).
        Route::middleware('can:integrations.view')->prefix('integration')->name('integration')->group(function (): void {
            Route::get('/', [IntegrationCenterController::class, 'show']);
            Route::post('/clients', [IntegrationCenterController::class, 'storeClient'])->name('.clients.store');
            Route::put('/clients/{apiClient}', [IntegrationCenterController::class, 'updateClient'])->name('.clients.update');
            Route::post('/clients/{apiClient}/rotate', [IntegrationCenterController::class, 'rotateClient'])->middleware('throttle:10,1')->name('.clients.rotate');
            Route::post('/clients/{apiClient}/revoke', [IntegrationCenterController::class, 'revokeClient'])->name('.clients.revoke');
            Route::post('/webhooks', [IntegrationCenterController::class, 'storeWebhook'])->name('.webhooks.store');
            Route::put('/webhooks/{webhook}', [IntegrationCenterController::class, 'updateWebhook'])->name('.webhooks.update');
            Route::post('/webhooks/{webhook}/rotate-secret', [IntegrationCenterController::class, 'rotateWebhook'])->middleware('throttle:10,1')->name('.webhooks.rotate');
            Route::post('/webhooks/{webhook}/resume', [IntegrationCenterController::class, 'resumeWebhook'])->name('.webhooks.resume');
            Route::post('/deliveries/{delivery}/retry', [IntegrationCenterController::class, 'retryDelivery'])->middleware('throttle:30,1')->name('.deliveries.retry');
        });
    });

    // The System Settings page's "API management" and "Integrations" entries (EUISIS route names) open the
    // Core's Integration Center, on its API clients and webhooks tabs.
    Route::middleware('can:integrations.view')->group(function (): void {
        Route::get('/api-management', fn () => redirect()->route('core.integration', ['tab' => 'clients']))->name('api-management.index');
        Route::get('/integrations', fn () => redirect()->route('core.integration', ['tab' => 'webhooks']))->name('integrations.index');
    });

    // The EUISIS System Settings page over the Core settings (SettingsController::TABS).
    Route::middleware('can:system-settings.view')->group(function (): void {
        Route::get('/system-settings', [SettingsController::class, 'index'])->name('system-settings.index');
        foreach (['general' => 'general', 'localization' => 'localization', 'appearance' => 'appearance', 'security' => 'security', 'id-cards' => 'id_cards',
            'notifications' => 'notifications', 'email' => 'email', 'sms' => 'sms', 'telegram' => 'telegram', 'field-work-gps' => 'field_work_gps',
            'situation-room' => 'situation_room', 'intelligence' => 'intelligence', 'assistant' => 'assistant'] as $path => $section) {
            Route::patch("/system-settings/{$path}", [SettingsController::class, 'update'])->defaults('section', $section)
                ->middleware('can:system-settings.update')->name("system-settings.{$path}.update");
        }
        Route::post('/system-settings/test-email', [SettingsController::class, 'testEmail'])->middleware(['can:system-settings.update', 'throttle:5,1'])->name('system-settings.test-email');
        Route::post('/system-settings/test-sms', [SettingsController::class, 'testSms'])->middleware(['can:system-settings.update', 'throttle:5,1'])->name('system-settings.test-sms');
        Route::post('/system-settings/test-telegram', [SettingsController::class, 'testTelegram'])->middleware(['can:system-settings.update', 'throttle:5,1'])->name('system-settings.test-telegram');
        Route::post('/system-settings/clear-cache', [SettingsController::class, 'clearCache'])->middleware('can:system-settings.update')->name('system-settings.clear-cache');
    });
    // The EUISIS ID card template designer (SystemSettings/IdCardTemplates), the EUISIS routes.
    Route::middleware('can:id_card_templates.view')->prefix('system-settings/id-card-templates')->name('id-card-templates.')->group(function (): void {
        Route::get('/', [IdCardTemplateController::class, 'index'])->name('index');
        Route::post('/', [IdCardTemplateController::class, 'store'])->middleware('can:id_card_templates.create')->name('store');
        Route::patch('/{template}', [IdCardTemplateController::class, 'update'])->middleware('can:id_card_templates.update')->name('update');
        Route::delete('/{template}', [IdCardTemplateController::class, 'destroy'])->middleware('can:id_card_templates.delete')->name('destroy');
        Route::post('/{template}/default', [IdCardTemplateController::class, 'setDefault'])->middleware('can:id_card_templates.set_default')->name('set-default');
        Route::get('/{template}/background/{side}', [IdCardTemplateController::class, 'background'])
            ->whereIn('side', ['front', 'back', 'logo-primary', 'logo-secondary', 'seal', 'signature'])->name('background');
    });

    Route::get('/audit-logs', [CoreController::class, 'auditLogs'])->middleware('can:audit.view')->name('audit-logs.index');
    Route::post('/code-rules/preview-code', [OrganizationController::class, 'previewCode'])->name('code-rules.preview-code');

    // Organizations
    Route::middleware('can:organizations.view')->group(function (): void {
        Route::get('/organizations', [OrganizationController::class, 'index'])->name('organizations.index');
        Route::get('/organizations/create', [OrganizationController::class, 'create'])->middleware('can:organizations.create')->name('organizations.create');
        Route::post('/organizations', [OrganizationController::class, 'store'])->middleware('can:organizations.create')->name('organizations.store');
        Route::get('/organizations/parent-options', [OrganizationController::class, 'parentOptions'])->name('organizations.parent-options');
        Route::get('/organizations/{organization}', [OrganizationController::class, 'show'])->name('organizations.show');
        Route::get('/organizations/{organization}/organogram', [OrganizationController::class, 'organogram'])->name('organizations.organogram');
        Route::get('/organizations/{organization}/edit', [OrganizationController::class, 'edit'])->middleware('can:organizations.update')->name('organizations.edit');
        Route::patch('/organizations/{organization}', [OrganizationController::class, 'update'])->middleware('can:organizations.update')->name('organizations.update');
        Route::delete('/organizations/{organization}', [OrganizationController::class, 'archive'])->middleware('can:organizations.archive')->name('organizations.archive');
        Route::patch('/organizations/{organization}/deactivate', [OrganizationController::class, 'deactivate'])->middleware('can:organizations.update')->name('organizations.deactivate');
        Route::delete('/organizations/{organization}/destroy', [OrganizationController::class, 'destroy'])->middleware('can:organizations.delete')->name('organizations.destroy');
        Route::get('/organizations/{organization}/units/options', [OrganizationUnitController::class, 'optionsFor'])->name('organizations.units.options');
        Route::get('/organizations/{organization}/units/tree', [OrganizationUnitController::class, 'tree'])->name('organizations.units.tree');
    });

    // Organization types and unit types
    Route::middleware('can:organization-types.viewAny')->group(function (): void {
        Route::get('/organization-types', [StructureTypeController::class, 'organizationTypes'])->name('organization-types.index');
        Route::get('/organization-types/create', [StructureTypeController::class, 'createOrganizationType'])->middleware('can:organization-types.create')->name('organization-types.create');
        Route::post('/organization-types', [StructureTypeController::class, 'storeOrganizationType'])->middleware('can:organization-types.create')->name('organization-types.store');
        Route::get('/organization-types/{organizationType}', [StructureTypeController::class, 'showOrganizationType'])->name('organization-types.show');
        Route::get('/organization-types/{organizationType}/edit', [StructureTypeController::class, 'editOrganizationType'])->middleware('can:organization-types.update')->name('organization-types.edit');
        Route::patch('/organization-types/{organizationType}', [StructureTypeController::class, 'updateOrganizationType'])->middleware('can:organization-types.update')->name('organization-types.update');
    });
    Route::middleware('can:organization-unit-types.viewAny')->group(function (): void {
        Route::get('/organization-unit-types', [StructureTypeController::class, 'unitTypes'])->name('organization-unit-types.index');
        Route::get('/organization-unit-types/create', [StructureTypeController::class, 'createUnitType'])->middleware('can:organization-unit-types.create')->name('organization-unit-types.create');
        Route::post('/organization-unit-types', [StructureTypeController::class, 'storeUnitType'])->middleware('can:organization-unit-types.create')->name('organization-unit-types.store');
        Route::get('/organization-unit-types/{organizationUnitType}', [StructureTypeController::class, 'showUnitType'])->name('organization-unit-types.show');
        Route::get('/organization-unit-types/{organizationUnitType}/edit', [StructureTypeController::class, 'editUnitType'])->middleware('can:organization-unit-types.update')->name('organization-unit-types.edit');
        Route::patch('/organization-unit-types/{organizationUnitType}', [StructureTypeController::class, 'updateUnitType'])->middleware('can:organization-unit-types.update')->name('organization-unit-types.update');
        Route::post('/organization-unit-types/{organizationUnitType}/archive', [StructureTypeController::class, 'archiveUnitType'])->middleware('can:organization-unit-types.archive')->name('organization-unit-types.archive');
        Route::post('/organization-unit-types/{organizationUnitType}/restore', [StructureTypeController::class, 'restoreUnitType'])->middleware('can:organization-unit-types.restore')->name('organization-unit-types.restore');
    });

    // Organization units
    Route::middleware('can:organization-units.viewAny')->group(function (): void {
        Route::get('/organization-units', [OrganizationUnitController::class, 'index'])->name('organization-units.index');
        Route::get('/organization-units/create', [OrganizationUnitController::class, 'create'])->middleware('can:organization-units.create')->name('organization-units.create');
        Route::post('/organization-units', [OrganizationUnitController::class, 'store'])->middleware('can:organization-units.create')->name('organization-units.store');
        Route::get('/organization-units/{organizationUnit}', [OrganizationUnitController::class, 'show'])->name('organization-units.show');
        Route::get('/organization-units/{organizationUnit}/edit', [OrganizationUnitController::class, 'edit'])->middleware('can:organization-units.update')->name('organization-units.edit');
        Route::patch('/organization-units/{organizationUnit}', [OrganizationUnitController::class, 'update'])->middleware('can:organization-units.update')->name('organization-units.update');
        Route::post('/organization-units/{organizationUnit}/archive', [OrganizationUnitController::class, 'archive'])->middleware('can:organization-units.archive')->name('organization-units.archive');
        Route::post('/organization-units/{organizationUnit}/restore', [OrganizationUnitController::class, 'restore'])->middleware('can:organization-units.restore')->name('organization-units.restore');
    });

    // The hierarchy: every reader sees the versions; city-wide administrators govern drafts (ADR 0008)
    Route::middleware('can:hierarchy-versions.viewAny')->group(function (): void {
        Route::get('/hierarchy-versions', [HierarchyController::class, 'index'])->name('hierarchy-versions.index');
        Route::get('/hierarchy-versions/create', [HierarchyVersionController::class, 'create'])->name('hierarchy-versions.create');
        Route::post('/hierarchy-versions', [HierarchyVersionController::class, 'store'])->name('hierarchy-versions.store');
        Route::get('/hierarchy-versions/{hierarchyVersion}', [HierarchyController::class, 'show'])->name('hierarchy-versions.show');
        Route::get('/hierarchy-versions/{hierarchyVersion}/edit', [HierarchyVersionController::class, 'edit'])->name('hierarchy-versions.edit');
        Route::match(['put', 'patch'], '/hierarchy-versions/{hierarchyVersion}', [HierarchyVersionController::class, 'update'])->name('hierarchy-versions.update');
        foreach (ManageHierarchyVersion::TRANSITIONS as $transition) {
            Route::post("/hierarchy-versions/{hierarchyVersion}/{$transition}", [HierarchyVersionController::class, 'transition'])
                ->defaults('transition', $transition)->name("hierarchy-versions.{$transition}");
        }
        Route::get('/hierarchy-versions/{hierarchyVersion}/tree', [HierarchyController::class, 'tree'])->name('hierarchy-versions.tree');
        Route::get('/hierarchy-versions/{hierarchyVersion}/tree/edit', [HierarchyVersionController::class, 'editTree'])->name('hierarchy-versions.tree.edit');
        Route::get('/hierarchy-versions/{hierarchyVersion}/organization-options', [HierarchyVersionController::class, 'organizationOptions'])->name('hierarchy-versions.organization-options');
        Route::post('/hierarchy-versions/{hierarchyVersion}/edges', [HierarchyVersionController::class, 'storeEdge'])->name('hierarchy-versions.edges.store');
        Route::patch('/hierarchy-versions/{hierarchyVersion}/edges/{organizationEdge}', [HierarchyVersionController::class, 'updateEdge'])->name('hierarchy-versions.edges.update');
        Route::delete('/hierarchy-versions/{hierarchyVersion}/edges/{organizationEdge}', [HierarchyVersionController::class, 'destroyEdge'])->name('hierarchy-versions.edges.destroy');
    });

    // Positions and establishments
    Route::middleware('can:positions.viewAny')->group(function (): void {
        Route::get('/positions', [PositionController::class, 'index'])->name('positions.index');
        Route::get('/positions/status', [PositionController::class, 'status'])->name('positions.status');
        Route::get('/positions/create', [PositionController::class, 'create'])->middleware('can:positions.create')->name('positions.create');
        Route::post('/positions', [PositionController::class, 'store'])->middleware('can:positions.create')->name('positions.store');
        Route::get('/positions/{position}', [PositionController::class, 'show'])->name('positions.show');
        Route::get('/positions/{position}/edit', [PositionController::class, 'edit'])->middleware('can:positions.update')->name('positions.edit');
        Route::patch('/positions/{position}', [PositionController::class, 'update'])->middleware('can:positions.update')->name('positions.update');
        Route::delete('/positions/{position}', [PositionController::class, 'archive'])->middleware('can:positions.archive')->name('positions.archive');
        Route::post('/positions/{position}/restore', [PositionController::class, 'restore'])->middleware('can:positions.restore')->name('positions.restore');
        // The position lifecycle (ADR 0010): ChangePositionLifecycle checks each step's permission and scope.
        Route::post('/positions/{position}/lifecycle', [PositionController::class, 'lifecycleStep'])->middleware('throttle:30,1')->name('positions.lifecycle');
        Route::post('/positions/{position}/approve-establishment', [PositionController::class, 'approveEstablishment'])->middleware('can:position-establishments.approve')->name('positions.approve-establishment');
    });
    Route::middleware('can:position-establishments.viewAny')->group(function (): void {
        Route::get('/position-establishments', [PositionController::class, 'establishments'])->name('position-establishments.index');
        Route::get('/position-establishments/{positionEstablishment}', [PositionController::class, 'showEstablishment'])->name('position-establishments.show');
        Route::get('/position-establishments/{positionEstablishment}/edit', [PositionController::class, 'editEstablishment'])->middleware('can:position-establishments.update')->name('position-establishments.edit');
        Route::patch('/position-establishments/{positionEstablishment}', [PositionController::class, 'updateEstablishment'])->middleware('can:position-establishments.update')->name('position-establishments.update');
        Route::post('/position-establishments/{positionEstablishment}/approve', [PositionController::class, 'approveDraftEstablishment'])->middleware('can:position-establishments.approve')->name('position-establishments.approve');
        Route::delete('/position-establishments/{positionEstablishment}', [PositionController::class, 'archiveEstablishment'])->middleware('can:position-establishments.archive')->name('position-establishments.archive');
    });

    // Employees
    Route::middleware('can:employees.view')->group(function (): void {
        Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
        Route::get('/employees/create', [EmployeeController::class, 'create'])->middleware('can:employees.create')->name('employees.create');
        Route::post('/employees', [EmployeeController::class, 'store'])->middleware('can:employees.create')->name('employees.store');
        Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');
        Route::get('/employees/{employee}/edit', [EmployeeController::class, 'edit'])->middleware('can:employees.update')->name('employees.edit');
        Route::patch('/employees/{employee}', [EmployeeController::class, 'update'])->middleware('can:employees.update')->name('employees.update');
        // The verified-employee stage (ADR 0008); EmployeeVerification checks the permission of each step.
        Route::post('/employees/{employee}/workflow/{step}', [EmployeeController::class, 'workflowStep'])->whereIn('step', ['submit', 'verify', 'return', 'approve'])->name('employees.workflow');
        // Documents, photo and signature (ADR 0009); EmployeeDocumentPolicy decides each step.
        Route::post('/employees/{employee}/documents', [EmployeeEvidenceController::class, 'store'])->middleware('throttle:30,1')->name('employees.documents.store');
        Route::post('/employees/{employee}/documents/{document}/replace', [EmployeeEvidenceController::class, 'replace'])->middleware('throttle:30,1')->name('employees.documents.replace');
        Route::post('/employees/{employee}/documents/{document}/{decision}', [EmployeeEvidenceController::class, 'decide'])->whereIn('decision', ['verify', 'reject'])->name('employees.documents.decide');
        Route::post('/employees/{employee}/documents/{document}/archive', [EmployeeEvidenceController::class, 'archive'])->name('employees.documents.archive');
        Route::delete('/employees/{employee}/documents/{document}', [EmployeeEvidenceController::class, 'discard'])->name('employees.documents.discard');
        Route::get('/employees/{employee}/documents/{document}/file', [EmployeeEvidenceController::class, 'file'])->middleware('throttle:120,1')->name('employees.documents.file');
    });

    // ID cards and card requests
    Route::middleware('can:cards.view')->group(function (): void {
        Route::get('/id-cards', [CardController::class, 'index'])->name('id-cards.index');
        Route::get('/id-cards/{card}', [CardController::class, 'show'])->name('id-cards.show');
        // The card as it will print, from the one renderer (ADR 0010).
        Route::get('/id-cards/{card}/preview', [CardController::class, 'preview'])->name('id-cards.preview');
        // The printed faces from the EUISIS renderer, under the EUISIS route names (the card page's print and export windows).
        Route::middleware('throttle:60,1')->group(function (): void {
            Route::get('/id-cards/{card}/preview/svg/front', [IdCardExportController::class, 'previewFrontSvg'])->name('id-cards.preview.svg.front');
            Route::get('/id-cards/{card}/preview/svg/back', [IdCardExportController::class, 'previewBackSvg'])->name('id-cards.preview.svg.back');
            Route::get('/id-cards/{card}/export/png/front', [IdCardExportController::class, 'exportFrontPng'])->name('id-cards.export.png.front');
            Route::get('/id-cards/{card}/export/png/back', [IdCardExportController::class, 'exportBackPng'])->name('id-cards.export.png.back');
            Route::get('/id-cards/{card}/export/png/both', [IdCardExportController::class, 'exportBothPng'])->name('id-cards.export.png.both');
        });
        // Card production (ADR 0010): IdCardPolicy and ManagePrintBatches / ManageDigitalCredentials decide each step.
        Route::post('/id-cards/{card}/quality-check', [CardController::class, 'qualityCheck'])->name('id-cards.quality-check');
        Route::post('/id-cards/{card}/reprint', [CardController::class, 'reprint'])->name('id-cards.reprint');
        Route::post('/id-cards/{card}/credential/{step}', [CardController::class, 'credential'])->whereIn('step', ['issue', 'rotate', 'revoke'])->middleware('throttle:20,1')->name('id-cards.credential');
        Route::middleware('can:cards.manage')->group(function (): void {
            // Printing is one step on the card in the Core (ADR 0006, decision 4).
            Route::post('/id-cards/{card}/prepare-print', [CardController::class, 'transition'])->defaults('transition', 'print')->name('id-cards.prepare-print');
            foreach (['issue' => 'issue', 'activate' => 'activate', 'report-lost' => 'report_lost', 'report-damaged' => 'report_damaged', 'revoke' => 'revoke', 'suspend' => 'suspend', 'reinstate' => 'reinstate'] as $path => $transition) {
                Route::post("/id-cards/{card}/{$path}", [CardController::class, 'transition'])->defaults('transition', $transition)->name("id-cards.{$path}");
            }
            Route::post('/id-cards/{card}/replace', [CardController::class, 'replace'])->name('id-cards.replace');
        });
    });
    // Print batches (ADR 0010) on the EUISIS PrintBatches screens; a batch is visible when all its cards are in scope.
    Route::middleware('can:card_print_batches.view')->group(function (): void {
        Route::get('/print-batches', [PrintBatchController::class, 'index'])->name('print-batches.index');
        Route::get('/print-batches/create', [PrintBatchController::class, 'create'])->middleware('can:card_print_batches.manage')->name('print-batches.create');
        Route::post('/print-batches', [PrintBatchController::class, 'store'])->middleware('can:card_print_batches.manage')->name('print-batches.store');
        Route::get('/print-batches/{printBatch}', [PrintBatchController::class, 'show'])->name('print-batches.show');
        Route::get('/print-batches/{printBatch}/sheet', [PrintBatchController::class, 'sheet'])->middleware(['can:card_print_batches.manage', 'throttle:20,1'])->name('print-batches.sheet');
        Route::post('/print-batches/{printBatch}/mark-printed', [PrintBatchController::class, 'markPrinted'])->middleware('can:card_print_batches.manage')->name('print-batches.mark-printed');
        Route::post('/print-batches/{printBatch}/cancel', [PrintBatchController::class, 'cancel'])->middleware('can:card_print_batches.manage')->name('print-batches.cancel');
    });
    Route::middleware('can:id-cards.viewAny')->group(function (): void {
        Route::get('/card-requests', [CardController::class, 'requests'])->name('card-requests.index');
        Route::get('/card-requests/create', [CardController::class, 'createRequest'])->middleware('can:card-requests.create')->name('card-requests.create');
        Route::post('/card-requests', [CardController::class, 'storeRequest'])->middleware('can:card-requests.create')->name('card-requests.store');
        Route::get('/card-requests/{cardRequest}', [CardController::class, 'showRequest'])->name('card-requests.show');
        Route::post('/card-requests/{cardRequest}/verify', [CardController::class, 'verifyRequest'])->middleware('can:card-requests.approve')->name('card-requests.verify');
        Route::post('/card-requests/{cardRequest}/approve', [CardController::class, 'approveRequest'])->middleware('can:card-requests.approve')->name('card-requests.approve');
        Route::post('/card-requests/{cardRequest}/reject', [CardController::class, 'rejectRequest'])->middleware('can:card-requests.approve')->name('card-requests.reject');
        Route::post('/card-requests/{cardRequest}/cancel', [CardController::class, 'cancelRequest'])->middleware('can:card-requests.create')->name('card-requests.cancel');
    });

    // Accounts
    Route::middleware('can:users.viewAny')->group(function (): void {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserController::class, 'create'])->middleware('can:users.create')->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->middleware('can:users.create')->name('users.store');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->middleware('can:users.update')->name('users.edit');
        Route::patch('/users/{user}', [UserController::class, 'update'])->middleware('can:users.update')->name('users.update');
        Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate'])->middleware('can:users.update')->name('users.deactivate');
        Route::post('/users/{user}/restore', [UserController::class, 'restore'])->middleware('can:users.restore')->name('users.restore');
        Route::post('/users/{user}/organization-scopes', [UserController::class, 'storeScope'])->middleware('can:users.update')->name('users.organization-scopes.store');
        Route::put('/users/{user}/organization-scopes/{scope}', [UserController::class, 'storeScope'])->middleware('can:users.update')->name('users.organization-scopes.update');
        Route::delete('/users/{user}/organization-scopes/{scope}', [UserController::class, 'destroyScope'])->middleware('can:users.update')->name('users.organization-scopes.destroy');
    });

    // Roles and their permissions (ADR 0009); changes are city-wide (ManageRoles checks roles.manage).
    Route::middleware('can:roles.viewAny')->group(function (): void {
        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::get('/roles/create', [RoleController::class, 'create'])->middleware('can:roles.manage')->name('roles.create');
        Route::post('/roles', [RoleController::class, 'store'])->middleware('can:roles.manage')->name('roles.store');
        Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])->middleware('can:roles.manage')->name('roles.edit');
        Route::patch('/roles/{role}', [RoleController::class, 'update'])->middleware('can:roles.manage')->name('roles.update');
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->middleware('can:roles.manage')->name('roles.destroy');
    });
});

// The public card check a printed QR opens (EUISIS /id-checker): card state first, the holder only after their code.
Route::get('/id-checker', [IdCheckerController::class, 'index'])->middleware('throttle:30,1')->name('id-checker.index');
Route::get('/id-checker/{cardUuid}', [IdCheckerController::class, 'show'])->middleware('throttle:30,1')->name('id-checker.show');
Route::post('/id-checker/{cardUuid}/send-otp', [IdCheckerController::class, 'sendOtp'])->middleware('throttle:3,10')->name('id-checker.send-otp');
Route::post('/id-checker/{cardUuid}/verify-otp', [IdCheckerController::class, 'verifyOtp'])->middleware('throttle:10,1')->name('id-checker.verify-otp');
