<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\Role;
use App\Models\User;
use App\Services\Api\OrganizationDataPresenter;
use Spatie\Permission\Models\Permission;

/*
 * An organization's short name, official contact and location: saved from the
 * forms, cleared when emptied, offered to field work as the expected point,
 * and sent to other systems through the master-data API.
 */

beforeEach(function (): void {
    $permissions = ['organizations.view', 'organizations.create', 'organizations.update', 'field_work.create_own'];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    Role::findOrCreate('Super Admin', 'web')->givePermissionTo($permissions);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Admin');
    $this->type = OrganizationType::query()->create(['code' => 'OLC-TYPE', 'name_en' => 'Bureau', 'name_am' => 'ቢሮ']);
});

function olcPayload(array $overrides = []): array
{
    return array_merge([
        'organization_type_id' => test()->type->id,
        'code' => 'OLC-1',
        'name_en' => 'Civil Service Bureau',
        'name_am' => 'የሲቪል ሰርቪስ ቢሮ',
        'status' => 'active',
        'short_name_en' => 'CSB',
        'short_name_am' => 'ሲሰቢ',
        'address_en' => 'Arada, Woreda 1, Churchill Avenue',
        'address_am' => 'አራዳ፣ ወረዳ 1፣ ቸርችል ጎዳና',
        'latitude' => '9.0321',
        'longitude' => '38.7469',
        'geofence_radius_m' => '150',
        'official_email' => 'info@csb.example.gov.et',
        'website' => 'csb.example.gov.et',
        'po_box' => '1234',
    ], $overrides);
}

it('saves the location and contact of a new organization', function (): void {
    $this->actingAs($this->admin)
        ->post(route('organizations.store'), olcPayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $organization = Organization::query()->where('name_en', 'Civil Service Bureau')->sole();

    expect($organization->short_name_en)->toBe('CSB')
        ->and($organization->address_am)->toBe('አራዳ፣ ወረዳ 1፣ ቸርችል ጎዳና')
        ->and($organization->latitude)->toBe(9.0321)
        ->and($organization->longitude)->toBe(38.7469)
        ->and($organization->geofence_radius_m)->toBe(150)
        ->and($organization->official_email)->toBe('info@csb.example.gov.et')
        // A bare domain is stored as a link.
        ->and($organization->website)->toBe('https://csb.example.gov.et')
        ->and($organization->po_box)->toBe('1234');
});

it('refuses a point without its longitude, an out-of-range radius and a malformed email', function (): void {
    $this->actingAs($this->admin)
        ->post(route('organizations.store'), olcPayload([
            'longitude' => '',
            'geofence_radius_m' => '999999',
            'official_email' => 'not-an-email',
        ]))
        ->assertSessionHasErrors(['longitude', 'geofence_radius_m', 'official_email']);
});

it('clears a field the edit form empties and keeps one it leaves out', function (): void {
    $this->actingAs($this->admin)->post(route('organizations.store'), olcPayload())->assertSessionHasNoErrors();
    $organization = Organization::query()->where('name_en', 'Civil Service Bureau')->sole();

    $this->actingAs($this->admin)
        ->patch(route('organizations.update', $organization), [
            'organization_type_id' => $this->type->id,
            'code' => $organization->code,
            'name_en' => 'Civil Service Bureau',
            'name_am' => 'የሲቪል ሰርቪስ ቢሮ',
            'status' => 'active',
            'latitude' => '',
            'longitude' => '',
            'geofence_radius_m' => '',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $organization->refresh();
    expect($organization->latitude)->toBeNull()
        ->and($organization->geofence_radius_m)->toBeNull()
        ->and($organization->official_email)->toBe('info@csb.example.gov.et');
});

it('offers the organization point to field work as the expected location', function (): void {
    $this->actingAs($this->admin)->post(route('organizations.store'), olcPayload())->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->getJson(route('employee.field-work.lookup.organizations', ['q' => 'Civil Service']))
        ->assertOk()
        ->assertJsonPath('data.0.latitude', 9.0321)
        ->assertJsonPath('data.0.longitude', 38.7469)
        ->assertJsonPath('data.0.geofence_radius_m', 150);
});

it('sends contact and location to other systems through the API presenter', function (): void {
    $this->actingAs($this->admin)->post(route('organizations.store'), olcPayload())->assertSessionHasNoErrors();
    $organization = Organization::query()->where('name_en', 'Civil Service Bureau')->sole();

    $payload = app(OrganizationDataPresenter::class)->organization($organization);

    expect($payload['short_name_en'])->toBe('CSB')
        ->and($payload['contact']['email'])->toBe('info@csb.example.gov.et')
        ->and($payload['location']['latitude'])->toBe(9.0321)
        ->and($payload['location']['address_en'])->toBe('Arada, Woreda 1, Churchill Avenue');
});
