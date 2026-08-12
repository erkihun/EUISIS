<?php

declare(strict_types=1);

use App\Enums\EstablishmentStatus;
use App\Enums\OrganizationStatus;
use App\Enums\VacancyAnnouncementStatus;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\User;
use App\Models\VacancyAnnouncement;
use App\Models\VacancyAnnouncementPosition;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    app()->setLocale('en');

    foreach ([
        'position-establishments.viewAny',
        'position-establishments.view',
        'vacancy-announcements.viewAny',
        'vacancy-announcements.view',
        'vacancy-announcements.create',
        'vacancy-announcements.update',
        'vacancy-announcements.publish',
        'vacancy-announcements.close',
        'vacancy-announcements.delete',
    ] as $perm) {
        Permission::findOrCreate($perm, 'web');
    }

    Role::findOrCreate('Super Admin', 'web')->syncPermissions(Permission::all());
});

function makeTestOrgForAnnouncement(): Organization
{
    $orgType = OrganizationType::query()->firstOrCreate(
        ['code' => 'TEST-TYPE-ANN'],
        ['name_en' => 'Test Type', 'is_active' => true],
    );

    return Organization::query()->create([
        'organization_type_id' => $orgType->id,
        'code' => 'ORG-ANN-'.uniqid(),
        'name_en' => 'Test Organization',
        'status' => OrganizationStatus::Active,
        'effective_from' => now()->toDateString(),
    ]);
}

function superAdminForVacancy(): User
{
    $user = User::factory()->create();
    $user->assignRole('Super Admin');

    return $user;
}

function makeApprovedEstablishment(): PositionEstablishment
{
    $org = makeTestOrgForAnnouncement();
    $pos = (new Position)->forceFill([
        'organization_id' => $org->id,
        'job_position_code' => 'JPC-'.uniqid(),
        'title_en' => 'Test Position',
        'is_active' => true,
    ]);
    $pos->save();

    return PositionEstablishment::create([
        'establishment_number' => 'EST-'.uniqid(),
        'organization_id' => $org->id,
        'position_id' => $pos->id,
        'approved_slots' => 3,
        'effective_from' => '2026-01-01',
        'status' => EstablishmentStatus::Approved->value,
    ]);
}

it('super admin can create a vacancy announcement draft', function (): void {
    $user = superAdminForVacancy();
    $establishment = makeApprovedEstablishment();

    $response = $this->actingAs($user)->post(route('vacancy-announcements.store'), [
        'title_en' => 'HR Manager Vacancy',
        'positions' => [
            [
                'position_establishment_id' => $establishment->id,
                'vacancy_slots' => 2,
            ],
        ],
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    expect(VacancyAnnouncement::where('title_en', 'HR Manager Vacancy')->exists())->toBeTrue();
    expect(VacancyAnnouncementPosition::where('position_establishment_id', $establishment->id)->exists())->toBeTrue();
});

it('can create one announcement with multiple organization position vacancies', function (): void {
    $user = superAdminForVacancy();
    $firstEstablishment = makeApprovedEstablishment();
    $secondEstablishment = makeApprovedEstablishment();

    $response = $this->actingAs($user)->post(route('vacancy-announcements.store'), [
        'title_en' => 'Multi Organization Transfer Vacancy',
        'positions' => [
            [
                'position_establishment_id' => $firstEstablishment->id,
                'vacancy_slots' => 2,
            ],
            [
                'position_establishment_id' => $secondEstablishment->id,
                'vacancy_slots' => 4,
            ],
        ],
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    $announcement = VacancyAnnouncement::where('title_en', 'Multi Organization Transfer Vacancy')->firstOrFail();

    expect($announcement->positions)->toHaveCount(2)
        ->and($announcement->positions()->sum('vacancy_slots'))->toBe(6);
});

it('cannot create announcement for unapproved establishment', function (): void {
    $user = superAdminForVacancy();
    $org = makeTestOrgForAnnouncement();
    $pos = (new Position)->forceFill(['organization_id' => $org->id, 'job_position_code' => 'JPC-'.uniqid(), 'title_en' => 'Pos', 'is_active' => true]);
    $pos->save();

    $establishment = PositionEstablishment::create([
        'establishment_number' => 'EST-DRAFT-'.uniqid(),
        'organization_id' => $org->id,
        'position_id' => $pos->id,
        'approved_slots' => 3,
        'effective_from' => '2026-01-01',
        'status' => EstablishmentStatus::Draft->value,
    ]);

    $response = $this->actingAs($user)->postJson(route('vacancy-announcements.store'), [
        'title_en' => 'Blocked Vacancy',
        'positions' => [
            [
                'position_establishment_id' => $establishment->id,
                'vacancy_slots' => 1,
            ],
        ],
    ]);

    expect($response->status())->toBe(422);
    expect(VacancyAnnouncement::where('title_en', 'Blocked Vacancy')->exists())->toBeFalse();
});

it('can publish a draft announcement', function (): void {
    $user = superAdminForVacancy();
    $establishment = makeApprovedEstablishment();

    $announcement = VacancyAnnouncement::create([
        'announcement_number' => 'VCY-TEST-'.uniqid(),
        'title_en' => 'Test Vacancy',
        'status' => VacancyAnnouncementStatus::Draft->value,
    ]);

    VacancyAnnouncementPosition::create([
        'vacancy_announcement_id' => $announcement->id,
        'position_establishment_id' => $establishment->id,
        'organization_id' => $establishment->organization_id,
        'organization_unit_id' => $establishment->organization_unit_id,
        'position_id' => $establishment->position_id,
        'vacancy_slots' => 2,
    ]);

    $response = $this->actingAs($user)->post(route('vacancy-announcements.publish', $announcement->id));

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    expect($announcement->fresh()->status)->toBe(VacancyAnnouncementStatus::Published);
});

it('can close a published announcement', function (): void {
    $user = superAdminForVacancy();
    $establishment = makeApprovedEstablishment();

    $announcement = VacancyAnnouncement::create([
        'announcement_number' => 'VCY-CLOSE-'.uniqid(),
        'title_en' => 'Close Me',
        'status' => VacancyAnnouncementStatus::Published->value,
    ]);

    VacancyAnnouncementPosition::create([
        'vacancy_announcement_id' => $announcement->id,
        'position_establishment_id' => $establishment->id,
        'organization_id' => $establishment->organization_id,
        'organization_unit_id' => $establishment->organization_unit_id,
        'position_id' => $establishment->position_id,
        'vacancy_slots' => 1,
    ]);

    $this->actingAs($user)->post(route('vacancy-announcements.close', $announcement->id));

    expect($announcement->fresh()->status)->toBe(VacancyAnnouncementStatus::Closed);
});
