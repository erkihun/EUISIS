<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\TransferAnnouncement;
use App\Models\User;
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
            ->where('cafeteria', null));
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
        ->toContain("t('employeePortal.cafeteriaSubsidy')")
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
