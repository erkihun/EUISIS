<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeCorrectionRequest;
use App\Models\Entitlement;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\Provider;
use App\Models\ProviderType;
use App\Models\PublicHoliday;
use App\Models\ServiceType;
use App\Models\TransferAnnouncement;
use App\Models\TransportPass;
use App\Models\TransportTransaction;
use App\Models\User;
use App\Support\DailyActivity\DailyActivityRoles;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * /my-portal — the one screen an ordinary employee uses.
 *
 * The account and the personnel record are separate rows joined on email, so
 * both the linked and the unlinked case have to render.
 */
function portalEmployee(string $email): array
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'PRT-TYPE'], ['name_en' => 'Portal type']);

    $organization = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'PRT-ORG',
        'name_en' => 'Portal Organization',
        'name_am' => 'የፖርታል ተቋም',
        'status' => 'active',
    ]);

    $unit = OrganizationUnit::query()->create([
        'organization_id' => $organization->id,
        'code' => 'PRT-U1',
        'name_en' => 'Portal Unit',
        'name_am' => 'የፖርታል ክፍል',
        'unit_type' => 'department',
        'status' => 'active',
    ]);

    $position = Position::query()->create([
        'organization_id' => $organization->id,
        'organization_unit_id' => $unit->id,
        'job_position_code' => 'PRT-P1',
        'title_en' => 'Portal Officer',
        'title_am' => 'የፖርታል ኦፊሰር',
        'is_active' => true,
    ]);

    $employee = Employee::query()->create([
        'employee_number' => 'PRT-0001',
        'first_name' => 'Portal',
        'last_name' => 'Person',
        'full_name' => 'Portal Person',
        'email' => $email,
        'status' => 'active',
    ]);

    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id,
        'organization_id' => $organization->id,
        'organization_unit_id' => $unit->id,
        'position_id' => $position->id,
        'assignment_status' => 'active',
        'effective_from' => now()->toDateString(),
        'is_current' => true,
    ]);

    $employee->update(['current_assignment_id' => $assignment->id]);

    return compact('employee', 'organization', 'unit', 'position');
}

test('the portal carries an Amharic reading for every placement name', function (): void {
    $user = User::factory()->create(['email' => 'portal@example.test', 'status' => 'active']);
    portalEmployee('portal@example.test');

    $this->actingAs($user)
        ->get(route('employee.portal'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Employee/Portal')
            ->where('employee.employee_number', 'PRT-0001')
            ->where('assignment.organization', 'Portal Organization')
            ->where('assignment.organization_am', 'የፖርታል ተቋም')
            ->where('assignment.organization_unit_am', 'የፖርታል ክፍል')
            ->where('assignment.position_am', 'የፖርታል ኦፊሰር'));
});

/* An account whose email matches no employee must still render a page. */
test('the portal renders an explanation when no employee is linked', function (): void {
    $user = User::factory()->create(['email' => 'unlinked@example.test', 'status' => 'active']);

    $this->actingAs($user)
        ->get(route('employee.portal'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Employee/Portal')
            ->where('employee', null)
            ->missing('cafeteria'));
});

/*
 * The portal is the screen an Amharic-speaking employee is most likely to use.
 * It previously called t() once and hardcoded every other string, so this
 * guards the markup contract the way the employee form tests do.
 */
test('the portal page holds no hardcoded English chrome', function (): void {
    $source = file_get_contents(dirname(__DIR__, 3).'/resources/js/Pages/Employee/Portal.tsx');

    foreach ([
        'Café Balance', 'Days Left', 'Active Apps', 'Cafeteria Subsidy', 'Daily Rate',
        'Week Remaining', 'Recent Transactions', 'My Transfer Applications',
        'No applications yet.', 'Open Announcements', 'My Services', 'No active ID card.',
        'View all', "'en-US'",
    ] as $literal) {
        expect($source)->not->toContain($literal);
    }

    expect($source)
        ->toContain("t('employeePortal.upcomingHolidays')")
        ->toContain('localizedName');
});

test('every portal string is translated in both locales', function (string $locale): void {
    $source = file_get_contents(dirname(__DIR__, 3)."/resources/js/i18n/{$locale}/employeePortal.ts");

    foreach (['title:', 'cafeBalance:', 'dailyRate:', 'myServices:', 'noProfileTitle:', 'weekdays:'] as $key) {
        expect($source)->toContain($key);
    }
})->with(['en', 'am']);

// ── Announcements inside the portal ────────────────────────────────────────

function portalAnnouncement(array $ctx, string $status = 'published'): TransferAnnouncement
{
    return TransferAnnouncement::query()->create([
        'organization_id' => $ctx['organization']->id,
        'position_id' => $ctx['position']->id,
        'created_by' => User::factory()->create()->id,
        'grade_level' => 'VII',
        'status' => $status,
        'opening_date' => now()->subDay()->toDateString(),
        'closing_date' => now()->addWeek()->toDateString(),
        'published_at' => now()->subDay(),
    ]);
}

test('the portal lists open announcements under my-portal', function (): void {
    $user = User::factory()->create(['email' => 'browse@example.test', 'status' => 'active']);
    portalAnnouncement(portalEmployee('browse@example.test'));

    $this->actingAs($user)
        ->get(route('employee.announcements'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Employee/Announcements')
            ->has('announcements.data', 1)
            ->where('has_employee', true));
});

test('an announcement opens inside the portal rather than on the public site', function (): void {
    $user = User::factory()->create(['email' => 'detail@example.test', 'status' => 'active']);
    $announcement = portalAnnouncement(portalEmployee('detail@example.test'));

    $this->actingAs($user)
        ->get(route('employee.announcements.show', $announcement))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Employee/AnnouncementShow')
            ->where('already_applied', false)
            ->where('announcement.position_title_am', 'የፖርታል ኦፊሰር'));
});

test('the apply form renders inside the portal', function (): void {
    $user = User::factory()->create(['email' => 'apply@example.test', 'status' => 'active']);
    $announcement = portalAnnouncement(portalEmployee('apply@example.test'));

    $this->actingAs($user)
        ->get(route('employee.announcements.apply', $announcement))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Employee/AnnouncementApply'));
});

/* A draft announcement must not be reachable through the portal either. */
test('the portal refuses an announcement that is not published', function (): void {
    $user = User::factory()->create(['email' => 'draft@example.test', 'status' => 'active']);
    $announcement = portalAnnouncement(portalEmployee('draft@example.test'), 'draft');

    $this->actingAs($user)
        ->get(route('employee.announcements.show', $announcement))
        ->assertNotFound();
});

/* Every portal link must stay in the portal. */
test('portal pages no longer link out to the public announcement routes', function (string $page): void {
    $source = file_get_contents(dirname(__DIR__, 3)."/resources/js/Pages/Employee/{$page}.tsx");

    expect($source)->not->toContain('public.transfer-announcements');
})->with(['Portal', 'MyTransferApplications']);

/* The sidebar's "My Work" self-service group is shown only to linked accounts. */
test('the shared props say whether the account has an employee record', function (): void {
    $unlinked = User::factory()->create(['email' => 'nobody@example.test', 'status' => 'active']);
    $this->actingAs($unlinked)->get(route('employee.portal'))
        ->assertInertia(fn (Assert $page) => $page->where('has_employee_record', false));

    $linked = User::factory()->create(['email' => 'linked.flag@example.test', 'status' => 'active']);
    portalEmployee('linked.flag@example.test');
    $this->actingAs($linked)->get(route('employee.portal'))
        ->assertInertia(fn (Assert $page) => $page->where('has_employee_record', true));
});

/*
 * Every My Portal page uses the one PortalPage frame: same header bar, same
 * width, no page-drawn <h1> and no second menu beside the sidebar.
 */
test('every portal page uses the shared portal frame', function (): void {
    $pages = [...glob(resource_path('js/Pages/Employee/*.tsx')), ...glob(resource_path('js/Pages/Employee/DailyActivity/*.tsx'))];

    expect($pages)->not->toBeEmpty();
    foreach ($pages as $page) {
        $source = file_get_contents($page);
        expect($source)->toContain('<PortalPage')
            ->and($source)->not->toContain('<AuthenticatedLayout')
            ->and($source)->not->toContain('<h1');
    }
});

/** The My Portal menu definition: the dashboard link plus its categorized sections. */
function portalMenuSource(): string
{
    $sidebar = file_get_contents(resource_path('js/Components/AppSidebar.tsx'));
    preg_match('/const portalDashboard.*?(?=\/\*\* Staff with an employee record)/s', $sidebar, $match);
    expect($match)->not->toBeEmpty();

    return $match[0];
}

test('the My Portal menu is categorized and leaves out pages reached another way', function (): void {
    $menu = portalMenuSource();
    preg_match_all("/labelKey: 'nav\\.portalSection(\\w+)'/", $menu, $sections);

    expect($sections[1])->toBe(['Work', 'Records', 'Services'])
        // Removed on request: announcements (reached from My Applications) and
        // My Profile (account menu and dashboard profile card).
        ->and($menu)->not->toContain("'employee.announcements'")
        ->not->toContain("'employee.profile'");
});

test('every My Portal menu label exists in English and Amharic', function (): void {
    preg_match_all("/labelKey: 'nav\\.(\\w+)'/", portalMenuSource(), $keys);
    $sources = [
        'en' => file_get_contents(resource_path('js/i18n/en/navigation.ts')).file_get_contents(resource_path('js/i18n/en.ts')),
        'am' => file_get_contents(resource_path('js/i18n/am/navigation.ts')).file_get_contents(resource_path('js/i18n/am.ts')),
    ];

    foreach (array_unique($keys[1]) as $key) {
        foreach ($sources as $locale => $source) {
            expect(preg_match('/\\b'.$key.':\\s*[\'"]/', $source))->toBe(1, "nav.{$key} missing in {$locale}");
        }
    }
});

test('every My Portal menu entry opens for an employee-only account', function (): void {
    $user = User::factory()->create(['email' => 'menu.check@example.test', 'status' => 'active']);
    $user->assignRole(DailyActivityRoles::EMPLOYEE_ROLE);
    portalEmployee('menu.check@example.test');
    preg_match_all("/routeName: '([^']+)'/", portalMenuSource(), $routes);

    expect($routes[1])->toContain('employee.portal', 'employee.performance.index', 'grievances.my');
    foreach ($routes[1] as $name) {
        $this->actingAs($user->fresh())->get(route($name))->assertOk();
    }
    $this->actingAs($user->fresh())->get(route('employee.portal'))->assertInertia(fn (Assert $page) => $page->where('is_employee_user', true));
});

test('the dashboard carries the figures an employee needs to act on', function (): void {
    $user = User::factory()->create(['email' => 'dash@example.test', 'status' => 'active']);
    $user->assignRole(DailyActivityRoles::EMPLOYEE_ROLE);
    $ctx = portalEmployee('dash@example.test');
    EmployeeCorrectionRequest::create(['employee_id' => $ctx['employee']->id, 'requested_by' => $user->id, 'field' => 'nationality', 'requested_value' => 'Ethiopian']);

    $this->actingAs($user->fresh())
        ->get(route('employee.portal'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Employee/Portal')
            ->has('daily_activity.week', 7)
            ->has('daily_activity.today_status')
            ->where('daily_activity.returned', 0)
            ->where('notifications.unread', 0)
            ->where('pending_requests', 1)
            ->where('requests.0.field', 'nationality')
            ->where('requests.0.status', 'pending')
            ->missing('requests.0.requested_value')
            ->has('daily_activity.recent')
            ->has('holidays')
            // Cafeteria belongs to My Services, not the dashboard.
            ->missing('cafeteria'));
});

test('the dashboard leaves out daily activity for accounts that cannot use it', function (): void {
    $user = User::factory()->create(['email' => 'nodash@example.test', 'status' => 'active']);
    portalEmployee('nodash@example.test');

    $this->actingAs($user)
        ->get(route('employee.portal'))
        ->assertInertia(fn (Assert $page) => $page->where('daily_activity', null)->where('pending_requests', 0));
});

// ── One profile per person ─────────────────────────────────────────────────

test('employee-linked accounts use My Profile instead of the account profile page', function (): void {
    $user = User::factory()->create(['email' => 'merged@example.test', 'status' => 'active']);
    portalEmployee('merged@example.test');

    $this->actingAs($user)->get(route('profile.edit'))->assertRedirect(route('employee.profile'));
    $this->actingAs($user)->get(route('employee.security'))->assertRedirect(route('employee.profile').'#account');

    $this->actingAs($user)->get(route('employee.profile'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Employee/SelfService')
            ->where('account.email', 'merged@example.test')
            ->where('account.mfa_enabled', false)
            ->missing('account.two_factor_secret')
            ->missing('account.password'));
});

test('accounts without an employee record keep the account profile page', function (): void {
    $user = User::factory()->create(['email' => 'staff.only@example.test', 'status' => 'active']);

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Profile/Edit'));
});

// ── My Services ────────────────────────────────────────────────────────────

test('my services shows transport passes and rides from the transport module', function (): void {
    $user = User::factory()->create(['email' => 'rider@example.test', 'status' => 'active']);
    $ctx = portalEmployee('rider@example.test');
    $employee = $ctx['employee'];

    $type = ServiceType::query()->firstOrCreate(['code' => 'transport'], ['name_en' => 'Transport', 'name_am' => 'ትራንስፖርት']);
    Entitlement::query()->create(['employee_id' => $employee->id, 'service_type_id' => $type->id, 'status' => 'active', 'effective_from' => now()->subMonth()->toDateString()]);

    $providerType = ProviderType::query()->firstOrCreate(['code' => 'transport'], ['name_en' => 'Transport']);
    $provider = Provider::query()->create(['provider_type_id' => $providerType->id, 'provider_code' => 'TP-1', 'name_en' => 'City Bus', 'name_am' => 'የከተማ አውቶቡስ']);
    // No route: on a fresh schema transport_routes still carries required
    // legacy columns (transport_plan_id, name) the transport module never fills.
    TransportPass::query()->create(['employee_id' => $employee->id, 'provider_id' => $provider->id, 'valid_from' => now()->subMonth()->toDateString(), 'valid_until' => now()->addMonth()->toDateString(), 'status' => 'active']);
    foreach (['accepted', 'rejected'] as $i => $status) {
        TransportTransaction::query()->create([
            'provider_id' => $provider->id, 'employee_id' => $employee->id,
            'scanned_at' => now()->subMinutes($i), 'transaction_date' => now()->toDateString(), 'status' => $status,
            'result_code' => $status, 'rejection_reason' => $status === 'rejected' ? 'Pass not valid for this route' : null,
            'scan_nonce' => 'n'.$i, 'qr_reference_hash' => 'secret-hash',
        ]);
    }

    $this->actingAs($user)->get(route('employee.services'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Employee/MyEntitlements')
            ->where('entitlements.0.activity.type', 'transport')
            ->where('entitlements.0.activity.passes.0.status', 'active')
            ->where('entitlements.0.activity.passes.0.provider_am', 'የከተማ አውቶቡስ')
            ->where('entitlements.0.activity.rides_this_month', 1)
            ->has('entitlements.0.activity.transactions', 2)
            ->where('entitlements.0.activity.transactions.1.rejection_reason', 'Pass not valid for this route')
            ->missing('entitlements.0.activity.transactions.0.qr_reference_hash')
            ->missing('entitlements.0.activity.transactions.0.scan_nonce'));
});

test('the old entitlements address sends employees to my services', function (): void {
    $user = User::factory()->create(['email' => 'oldlink@example.test', 'status' => 'active']);
    portalEmployee('oldlink@example.test');

    $this->actingAs($user)->get('/my-portal/entitlements')->assertRedirect(route('employee.services'));
});

test('the dashboard lists upcoming public holidays', function (): void {
    $user = User::factory()->create(['email' => 'holidays@example.test', 'status' => 'active']);
    portalEmployee('holidays@example.test');
    PublicHoliday::query()->create(['name_en' => 'Test Holiday', 'name_am' => 'የሙከራ በዓል', 'holiday_date' => now('Africa/Addis_Ababa')->addDays(10)->toDateString(), 'is_active' => true]);

    $this->actingAs($user)->get(route('employee.portal'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('holidays.0.name_en', 'Test Holiday')
            ->where('holidays.0.name_am', 'የሙከራ በዓል'));
});
