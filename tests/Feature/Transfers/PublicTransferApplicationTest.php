<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\Position;
use App\Models\User;
use Modules\Transfer\Contracts\Data\TransferAnnouncementStatus;
use Modules\Transfer\Contracts\Data\TransferApplicationStatus;
use Modules\Transfer\Domain\Models\TransferAnnouncement;
use Modules\Transfer\Domain\Models\TransferApplication;
use Modules\Transfer\Domain\Models\TransferSetting;

// ── Helpers ──────────────────────────────────────────────────────────────────

function ptaOrgType(): OrganizationType
{
    return OrganizationType::query()->firstOrCreate(
        ['code' => 'PTA-DEPT'],
        ['name_en' => 'PTA Department'],
    );
}

function ptaOrg(string $suffix): Organization
{
    return Organization::query()->create([
        'organization_type_id' => ptaOrgType()->id,
        'code' => 'PTA-'.$suffix,
        'name_en' => 'PTA Org '.$suffix,
        'status' => 'active',
    ]);
}

function ptaPosition(string $orgId): Position
{
    static $n = 0;
    $n++;

    return Position::query()->create([
        'organization_id' => $orgId,
        'title_en' => 'PTA Position '.$n,
        'job_position_code' => 'PTA-POS-'.$n,
        'is_active' => true,
    ]);
}

function ptaPublishedAnnouncement(
    string $orgId,
    string $posId,
    string $opening = '-1 day',
    string $closing = '+30 days',
): TransferAnnouncement {
    return TransferAnnouncement::query()->create([
        'organization_id' => $orgId,
        'position_id' => $posId,
        'number_of_vacancies' => 3,
        'opening_date' => now()->modify($opening)->toDateString(),
        'closing_date' => now()->modify($closing)->toDateString(),
        'status' => TransferAnnouncementStatus::Published,
        'created_by' => User::factory()->create()->id,
        'published_at' => now(),
    ]);
}

/**
 * Create a user whose email matches an active employee with an assignment.
 * The employee is placed in $releasingOrg (different from $announcementOrg).
 */
function ptaEmployeeUser(string $releasingOrgId): array
{
    $email = 'employee-'.uniqid().'@example.com';

    $user = User::factory()->create(['email' => $email]);

    $employee = Employee::query()->create([
        'employee_number' => 'EMP-'.strtoupper(uniqid()),
        'first_name' => 'Test',
        'last_name' => 'Employee',
        'full_name' => 'Test Employee',
        'email' => $email,
        'status' => EmployeeStatus::Active,
    ]);

    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id,
        'organization_id' => $releasingOrgId,
        'assignment_status' => 'active',
        'effective_from' => now()->subYear()->toDateString(),
        'is_current' => true,
    ]);

    $employee->update(['current_assignment_id' => $assignment->id]);
    $employee->refresh();

    // Ensure TransferSetting defaults exist
    TransferSetting::current();

    return compact('user', 'employee', 'assignment');
}

// ── Public show page ──────────────────────────────────────────────────────────

test('guest can view a published announcement detail', function (): void {
    $org = ptaOrg('SHOW1');
    $pos = ptaPosition($org->id);
    $ann = ptaPublishedAnnouncement($org->id, $pos->id);

    $this->get(route('public.transfer-announcements.show', $ann))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Public/TransferAnnouncements/Show'));
});

test('non-published announcement returns 404 on show page', function (): void {
    $org = ptaOrg('404ORG');
    $pos = ptaPosition($org->id);
    $ann = TransferAnnouncement::query()->create([
        'organization_id' => $org->id,
        'position_id' => $pos->id,
        'number_of_vacancies' => 1,
        'opening_date' => now()->addDay()->toDateString(),
        'closing_date' => now()->addDays(30)->toDateString(),
        'status' => TransferAnnouncementStatus::Draft,
        'created_by' => User::factory()->create()->id,
    ]);

    $this->get(route('public.transfer-announcements.show', $ann))
        ->assertNotFound();
});

// ── Apply page (GET) ──────────────────────────────────────────────────────────

test('guest clicking apply is redirected to login', function (): void {
    $org = ptaOrg('GUEST1');
    $pos = ptaPosition($org->id);
    $ann = ptaPublishedAnnouncement($org->id, $pos->id);

    $this->get(route('public.transfer-announcements.apply', $ann))
        ->assertRedirect(route('login'));
});

test('authenticated employee can open the apply page', function (): void {
    $announcementOrg = ptaOrg('ANN1');
    $releasingOrg = ptaOrg('REL1');
    $pos = ptaPosition($announcementOrg->id);
    $ann = ptaPublishedAnnouncement($announcementOrg->id, $pos->id);

    ['user' => $user] = ptaEmployeeUser($releasingOrg->id);

    $this->actingAs($user)
        ->get(route('public.transfer-announcements.apply', $ann))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Public/TransferAnnouncements/Apply'));
});

test('apply page redirects to show when announcement is closed', function (): void {
    $org = ptaOrg('CLOSED1');
    $pos = ptaPosition($org->id);

    $ann = TransferAnnouncement::query()->create([
        'organization_id' => $org->id,
        'position_id' => $pos->id,
        'number_of_vacancies' => 1,
        'opening_date' => now()->subDays(10)->toDateString(),
        'closing_date' => now()->subDay()->toDateString(),
        'status' => TransferAnnouncementStatus::Published,
        'created_by' => User::factory()->create()->id,
        'published_at' => now()->subDays(10),
    ]);

    $relOrg = ptaOrg('REL-CL1');
    ['user' => $user] = ptaEmployeeUser($relOrg->id);

    $this->actingAs($user)
        ->get(route('public.transfer-announcements.apply', $ann))
        ->assertRedirect(route('public.transfer-announcements.show', $ann));
});

// ── Submit application (POST) ─────────────────────────────────────────────────

test('authenticated employee can submit a transfer application', function (): void {
    $announcementOrg = ptaOrg('ANN2');
    $releasingOrg = ptaOrg('REL2');
    $pos = ptaPosition($announcementOrg->id);
    $ann = ptaPublishedAnnouncement($announcementOrg->id, $pos->id);

    ['user' => $user, 'employee' => $employee] = ptaEmployeeUser($releasingOrg->id);

    $this->actingAs($user)
        ->post(route('public.transfer-announcements.apply.store', $ann), [
            'cover_letter' => 'I would like to apply for this position.',
        ])
        ->assertRedirect(route('public.transfer-announcements.show', $ann));

    $this->assertDatabaseHas('transfer_applications', [
        'announcement_id' => $ann->id,
        'employee_id' => $employee->id,
        'status' => TransferApplicationStatus::Submitted->value,
    ]);
});

test('duplicate application is blocked', function (): void {
    $announcementOrg = ptaOrg('ANN3');
    $releasingOrg = ptaOrg('REL3');
    $pos = ptaPosition($announcementOrg->id);
    $ann = ptaPublishedAnnouncement($announcementOrg->id, $pos->id);

    ['user' => $user, 'employee' => $employee, 'assignment' => $assignment] = ptaEmployeeUser($releasingOrg->id);

    // Create an existing application
    TransferApplication::query()->create([
        'announcement_id' => $ann->id,
        'employee_id' => $employee->id,
        'current_assignment_id' => $assignment->id,
        'releasing_organization_id' => $releasingOrg->id,
        'receiving_organization_id' => $announcementOrg->id,
        'status' => TransferApplicationStatus::Submitted,
        'submitted_at' => now(),
    ]);

    $this->actingAs($user)
        ->post(route('public.transfer-announcements.apply.store', $ann), [])
        ->assertSessionHasErrors(['application']);
});

test('closed announcement cannot be applied to via POST', function (): void {
    $org = ptaOrg('CLOSED2');
    $pos = ptaPosition($org->id);

    $ann = TransferAnnouncement::query()->create([
        'organization_id' => $org->id,
        'position_id' => $pos->id,
        'number_of_vacancies' => 1,
        'opening_date' => now()->subDays(10)->toDateString(),
        'closing_date' => now()->subDay()->toDateString(),
        'status' => TransferAnnouncementStatus::Published,
        'created_by' => User::factory()->create()->id,
        'published_at' => now()->subDays(10),
    ]);

    $relOrg = ptaOrg('REL-CL2');
    ['user' => $user] = ptaEmployeeUser($relOrg->id);

    $this->actingAs($user)
        ->post(route('public.transfer-announcements.apply.store', $ann), [])
        ->assertSessionHasErrors(['application']);
});

test('application outside opening date range is blocked', function (): void {
    $announcementOrg = ptaOrg('FUTURE1');
    $releasingOrg = ptaOrg('REL-F1');
    $pos = ptaPosition($announcementOrg->id);

    $ann = TransferAnnouncement::query()->create([
        'organization_id' => $announcementOrg->id,
        'position_id' => $pos->id,
        'number_of_vacancies' => 2,
        'opening_date' => now()->addDays(5)->toDateString(),
        'closing_date' => now()->addDays(30)->toDateString(),
        'status' => TransferAnnouncementStatus::Published,
        'created_by' => User::factory()->create()->id,
        'published_at' => now(),
    ]);

    ['user' => $user] = ptaEmployeeUser($releasingOrg->id);

    $this->actingAs($user)
        ->post(route('public.transfer-announcements.apply.store', $ann), [])
        ->assertSessionHasErrors(['application']);
});

test('inactive employee cannot apply', function (): void {
    $announcementOrg = ptaOrg('ANN-INACT');
    $releasingOrg = ptaOrg('REL-INACT');
    $pos = ptaPosition($announcementOrg->id);
    $ann = ptaPublishedAnnouncement($announcementOrg->id, $pos->id);

    $email = 'inactive-'.uniqid().'@example.com';
    $user = User::factory()->create(['email' => $email]);
    $employee = Employee::query()->create([
        'employee_number' => 'EMP-INACT-'.strtoupper(uniqid()),
        'first_name' => 'Inactive',
        'last_name' => 'Employee',
        'full_name' => 'Inactive Employee',
        'email' => $email,
        'status' => EmployeeStatus::Suspended,
    ]);
    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id,
        'organization_id' => $releasingOrg->id,
        'assignment_status' => 'active',
        'effective_from' => now()->subYear()->toDateString(),
        'is_current' => true,
    ]);
    $employee->update(['current_assignment_id' => $assignment->id]);
    TransferSetting::current();

    $this->actingAs($user)
        ->post(route('public.transfer-announcements.apply.store', $ann), [])
        ->assertSessionHasErrors(['application']);
});

test('user without linked employee profile gets error redirect', function (): void {
    $announcementOrg = ptaOrg('ANN-NOEMP');
    $pos = ptaPosition($announcementOrg->id);
    $ann = ptaPublishedAnnouncement($announcementOrg->id, $pos->id);

    $user = User::factory()->create(['email' => 'no-employee-'.uniqid().'@example.com']);

    $this->actingAs($user)
        ->post(route('public.transfer-announcements.apply.store', $ann), [])
        ->assertRedirect(route('public.transfer-announcements.show', $ann));
});

test('show page marks already_applied true when employee has active application', function (): void {
    $announcementOrg = ptaOrg('ANN-APLID');
    $releasingOrg = ptaOrg('REL-APLID');
    $pos = ptaPosition($announcementOrg->id);
    $ann = ptaPublishedAnnouncement($announcementOrg->id, $pos->id);

    ['user' => $user, 'employee' => $employee, 'assignment' => $assignment] = ptaEmployeeUser($releasingOrg->id);

    TransferApplication::query()->create([
        'announcement_id' => $ann->id,
        'employee_id' => $employee->id,
        'current_assignment_id' => $assignment->id,
        'releasing_organization_id' => $releasingOrg->id,
        'receiving_organization_id' => $announcementOrg->id,
        'status' => TransferApplicationStatus::Submitted,
        'submitted_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('public.transfer-announcements.show', $ann))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('already_applied', true));
});

// ── Required documents and refusals in the visitor's language ─────────────────

test('the apply page lists every required document, from the settings and the announcement', function (): void {
    $announcementOrg = ptaOrg('ANN6');
    $releasingOrg = ptaOrg('REL6');
    $pos = ptaPosition($announcementOrg->id);
    $ann = ptaPublishedAnnouncement($announcementOrg->id, $pos->id);
    $ann->update(['required_documents' => ['Letter of release', ' Letter of release ']]);
    TransferSetting::current()->update(['required_documents' => ['Latest performance evaluation']]);

    ['user' => $user] = ptaEmployeeUser($releasingOrg->id);

    $this->actingAs($user)
        ->get(route('public.transfer-announcements.apply', $ann))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Public/TransferAnnouncements/Apply')
            ->where('announcement.required_documents', ['Latest performance evaluation', 'Letter of release']));
});

test('a missing required document is refused in the visitor language', function (): void {
    $announcementOrg = ptaOrg('ANN7');
    $releasingOrg = ptaOrg('REL7');
    $pos = ptaPosition($announcementOrg->id);
    $ann = ptaPublishedAnnouncement($announcementOrg->id, $pos->id);
    $ann->update(['required_documents' => ['Letter of release']]);

    ['user' => $user] = ptaEmployeeUser($releasingOrg->id);

    $expected = __('transfers.requiredDocumentMissing', ['type' => 'Letter of release'], 'am');

    $this->actingAs($user)
        ->withHeader('X-Locale', 'am')
        ->post(route('public.transfer-announcements.apply.store', $ann), ['cover_letter' => 'Please consider me.'])
        ->assertSessionHasErrors(['application' => $expected]);

    expect(TransferApplication::query()->count())->toBe(0)
        ->and($expected)->toContain('Letter of release')
        ->and($expected)->not->toContain('transfers.')
        ->and($expected)->not->toBe(__('transfers.requiredDocumentMissing', ['type' => 'Letter of release'], 'en'));
});

test('an ineligible employee is refused in the visitor language', function (): void {
    $announcementOrg = ptaOrg('ANN8');
    $releasingOrg = ptaOrg('REL8');
    $pos = ptaPosition($announcementOrg->id);
    $ann = ptaPublishedAnnouncement($announcementOrg->id, $pos->id);
    $ann->update(['eligibility_rules' => [['type' => 'minimum_service_months', 'operator' => 'greater_than_or_equal', 'value' => '600']]]);

    ['user' => $user] = ptaEmployeeUser($releasingOrg->id);

    $expected = __('transfers.notEligible', [], 'am');

    $this->actingAs($user)
        ->withHeader('X-Locale', 'am')
        ->post(route('public.transfer-announcements.apply.store', $ann), [])
        ->assertSessionHasErrors(['application' => $expected]);

    expect(TransferApplication::query()->count())->toBe(0)
        ->and($expected)->not->toBe('transfers.notEligible')
        ->and($expected)->not->toBe(__('transfers.notEligible', [], 'en'));
});
