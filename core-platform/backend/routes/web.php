<?php

use App\Http\Controllers\Web\CardController;
use App\Http\Controllers\Web\CoreController;
use App\Http\Controllers\Web\EmployeeController;
use App\Http\Controllers\Web\HierarchyController;
use App\Http\Controllers\Web\IdCheckerController;
use App\Http\Controllers\Web\MfaController;
use App\Http\Controllers\Web\OrganizationController;
use App\Http\Controllers\Web\OrganizationUnitController;
use App\Http\Controllers\Web\PositionController;
use App\Http\Controllers\Web\SessionController;
use App\Http\Controllers\Web\StructureTypeController;
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
        Route::get('/data-quality', [CoreController::class, 'dataQuality'])->middleware('can:audit.view')->name('data-quality');
        Route::get('/settings', [CoreController::class, 'settings'])->middleware('can:system-settings.view')->name('settings');
        Route::post('/exit', [SessionController::class, 'exitCore'])->name('exit');
    });

    Route::middleware('can:system-settings.view')->group(function (): void {
        Route::get('/system-settings', [CoreController::class, 'systemSettings'])->name('system-settings.index');
        foreach (['general' => 'general', 'localization' => 'localization', 'appearance' => 'appearance', 'security' => 'security', 'id-cards' => 'id_cards'] as $path => $section) {
            Route::patch("/system-settings/{$path}", [CoreController::class, 'updateSettings'])->defaults('section', $section)
                ->middleware('can:system-settings.update')->name("system-settings.{$path}.update");
        }
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

    // The published hierarchy (versions are prepared in EUISIS)
    Route::middleware('can:hierarchy-versions.viewAny')->group(function (): void {
        Route::get('/hierarchy-versions', [HierarchyController::class, 'index'])->name('hierarchy-versions.index');
        Route::get('/hierarchy-versions/{hierarchyVersion}', [HierarchyController::class, 'show'])->name('hierarchy-versions.show');
        Route::get('/hierarchy-versions/{hierarchyVersion}/tree', [HierarchyController::class, 'tree'])->name('hierarchy-versions.tree');
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
    });

    // ID cards and card requests
    Route::middleware('can:cards.view')->group(function (): void {
        Route::get('/id-cards', [CardController::class, 'index'])->name('id-cards.index');
        Route::get('/id-cards/{card}', [CardController::class, 'show'])->name('id-cards.show');
        Route::middleware('can:cards.manage')->group(function (): void {
            // Printing is one step on the card in the Core (ADR 0006, decision 4).
            Route::post('/id-cards/{card}/prepare-print', [CardController::class, 'transition'])->defaults('transition', 'print')->name('id-cards.prepare-print');
            foreach (['issue' => 'issue', 'activate' => 'activate', 'report-lost' => 'report_lost', 'report-damaged' => 'report_damaged', 'revoke' => 'revoke', 'suspend' => 'suspend', 'reinstate' => 'reinstate'] as $path => $transition) {
                Route::post("/id-cards/{card}/{$path}", [CardController::class, 'transition'])->defaults('transition', $transition)->name("id-cards.{$path}");
            }
            Route::post('/id-cards/{card}/replace', [CardController::class, 'replace'])->name('id-cards.replace');
        });
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
});

// The public card check a printed QR opens (EUISIS /id-checker): card state first, the holder only after their code.
Route::get('/id-checker', [IdCheckerController::class, 'index'])->middleware('throttle:30,1')->name('id-checker.index');
Route::get('/id-checker/{cardUuid}', [IdCheckerController::class, 'show'])->middleware('throttle:30,1')->name('id-checker.show');
Route::post('/id-checker/{cardUuid}/send-otp', [IdCheckerController::class, 'sendOtp'])->middleware('throttle:3,10')->name('id-checker.send-otp');
Route::post('/id-checker/{cardUuid}/verify-otp', [IdCheckerController::class, 'verifyOtp'])->middleware('throttle:10,1')->name('id-checker.verify-otp');
