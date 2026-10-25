<?php

declare(strict_types=1);

use App\Backbone\Contracts\AssignmentContextReader;
use App\Backbone\Contracts\CredentialVerification;
use App\Backbone\Contracts\EmployeeDirectory;
use App\Backbone\Contracts\OrganizationDirectory;
use App\Backbone\Contracts\OrganizationHierarchy;
use App\Backbone\Contracts\PositionDirectory;
use App\Backbone\Contracts\UnitDirectory;
use App\Backbone\Data\CredentialFormat;
use App\Enums\AssignmentStatus;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Enums\HierarchyVersionStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationClosurePath;
use App\Models\OrganizationEdge;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Services\IdCards\CardQrPayloadService;

/*
 * Core foundation Stage 1: the read contracts over the existing tables. They
 * return data objects, never models, and the "…For" reads answer only inside
 * the viewer's organization scope (OrganizationScopeService).
 */

beforeEach(function (): void {
    $type = OrganizationType::query()->create(['code' => 'CR-T', 'name_en' => 'Contract type']);
    $make = fn (string $code, string $status = 'active'): Organization => Organization::query()->create(['organization_type_id' => $type->id, 'code' => $code, 'name_en' => 'Org '.$code, 'name_am' => 'ተቋም '.$code, 'status' => $status]);
    $this->city = $make('CR-CITY');
    $this->bureau = $make('CR-BUREAU');
    $this->other = $make('CR-OTHER');
    $this->closed = $make('CR-CLOSED', 'inactive');

    $version = HierarchyVersion::query()->create(['version_name' => 'CR v1', 'status' => HierarchyVersionStatus::Published->value, 'effective_from' => now()->subYear()->toDateString()]);
    foreach ([$this->city, $this->bureau, $this->other, $this->closed] as $organization) {
        OrganizationClosurePath::query()->create(['hierarchy_version_id' => $version->id, 'ancestor_organization_id' => $organization->id, 'descendant_organization_id' => $organization->id, 'depth' => 0]);
    }
    OrganizationClosurePath::query()->create(['hierarchy_version_id' => $version->id, 'ancestor_organization_id' => $this->city->id, 'descendant_organization_id' => $this->bureau->id, 'depth' => 1]);
    OrganizationEdge::query()->create(['hierarchy_version_id' => $version->id, 'parent_organization_id' => $this->city->id, 'child_organization_id' => $this->bureau->id, 'relationship_type' => 'structural_parent']);
    OrganizationEdge::query()->create(['hierarchy_version_id' => $version->id, 'parent_organization_id' => $this->other->id, 'child_organization_id' => $this->bureau->id, 'relationship_type' => 'functional_reporting']);

    $this->unit = OrganizationUnit::query()->create(['organization_id' => $this->bureau->id, 'code' => 'CR-U1', 'name_en' => 'Unit one', 'unit_type' => 'department', 'status' => 'active']);
    OrganizationUnit::query()->create(['organization_id' => $this->bureau->id, 'code' => 'CR-U2', 'name_en' => 'Old unit', 'unit_type' => 'department', 'status' => 'archived']);
    $this->position = Position::query()->create(['organization_id' => $this->bureau->id, 'organization_unit_id' => $this->unit->id, 'job_position_code' => 'CR-P', 'title_en' => 'Officer', 'is_active' => true]);

    $this->employee = Employee::query()->create(['employee_number' => 'CR-E1', 'first_name' => 'Core', 'last_name' => 'Reader', 'full_name' => 'Core Reader', 'status' => EmployeeStatus::Active->value, 'national_id' => '1234567890123456', 'phone' => '0911000000']);
    $old = EmployeeAssignment::query()->create(['employee_id' => $this->employee->id, 'organization_id' => $this->other->id, 'is_current' => false, 'assignment_status' => AssignmentStatus::Closed->value, 'effective_from' => '2023-01-01', 'effective_to' => '2024-12-31']);
    $this->current = EmployeeAssignment::query()->create(['employee_id' => $this->employee->id, 'organization_id' => $this->bureau->id, 'organization_unit_id' => $this->unit->id, 'position_id' => $this->position->id, 'is_current' => true, 'assignment_status' => AssignmentStatus::Active->value, 'effective_from' => '2025-01-01']);
    $this->employee->forceFill(['current_assignment_id' => $this->current->id])->save();
    $this->oldAssignment = $old;

    // A viewer scoped to the other organization only.
    $this->outsider = User::factory()->create();
    $this->outsider->organizationScopes()->create(['organization_id' => $this->other->id, 'scope_type' => 'self', 'is_active' => true]);
    $this->insider = User::factory()->create();
    $this->insider->organizationScopes()->create(['organization_id' => $this->bureau->id, 'scope_type' => 'self', 'is_active' => true]);
});

it('finds organizations as data objects and only inside the viewer\'s scope', function (): void {
    $directory = app(OrganizationDirectory::class);

    expect($directory->find($this->bureau->id)?->code)->toBe('CR-BUREAU')
        ->and($directory->findByCode('CR-CITY')?->organizationUuid)->toBe($this->city->id)
        ->and($directory->find('not-a-uuid'))->toBeNull()
        ->and($directory->findFor($this->insider, $this->bureau->id)?->nameAm)->toBe('ተቋም CR-BUREAU')
        ->and($directory->findFor($this->outsider, $this->bureau->id))->toBeNull()
        ->and(collect($directory->activeFor($this->insider))->pluck('code')->all())->toBe(['CR-BUREAU'])
        ->and(collect($directory->activeFor(User::factory()->create()))->pluck('code')->all())->not->toContain('CR-CLOSED');
});

it('answers structure questions from the published hierarchy, keeping relationship types apart', function (): void {
    $hierarchy = app(OrganizationHierarchy::class);

    expect($hierarchy->childIds($this->city->id))->toBe([$this->bureau->id])
        ->and($hierarchy->ancestorIds($this->bureau->id))->toBe([$this->city->id])
        ->and($hierarchy->subtreeIds($this->city->id))->toBe([$this->city->id, $this->bureau->id])
        ->and(collect($hierarchy->relationships($this->bureau->id))->map(fn ($r) => $r->type.':'.$r->parentOrganizationUuid)->sort()->values()->all())
        ->toBe(['functional_reporting:'.$this->other->id, 'structural_parent:'.$this->city->id]);
});

it('lists an organization\'s active units and scopes a unit by its organization', function (): void {
    $units = app(UnitDirectory::class);

    expect(collect($units->forOrganization($this->bureau->id))->pluck('code')->all())->toBe(['CR-U1'])
        ->and(collect($units->forOrganization($this->bureau->id, activeOnly: false))->pluck('code')->all())->toBe(['CR-U1', 'CR-U2'])
        ->and($units->findFor($this->insider, $this->unit->id)?->organizationUuid)->toBe($this->bureau->id)
        ->and($units->findFor($this->outsider, $this->unit->id))->toBeNull();
});

it('reports position seats by the one capacity rule, without naming the holder', function (): void {
    $positions = app(PositionDirectory::class);
    $occupancy = $positions->occupancy($this->position->id);

    expect($occupancy->capacity)->toBe(1)
        ->and($occupancy->occupied)->toBe(1)
        ->and($occupancy->vacant())->toBe(0)
        ->and($positions->hasVacancy($this->position->id))->toBeFalse()
        ->and($positions->findFor($this->outsider, $this->position->id))->toBeNull()
        ->and(array_keys(get_object_vars($occupancy)))->toBe(['positionUuid', 'capacity', 'occupied', 'isActive']);

    $this->current->update(['assignment_status' => AssignmentStatus::Closed->value]);
    expect($positions->hasVacancy($this->position->id))->toBeTrue();

    $this->position->update(['is_active' => false]);
    expect($positions->hasVacancy($this->position->id))->toBeFalse();
});

it('reads employee identity and placement history without personal fields, inside the scope', function (): void {
    $employees = app(EmployeeDirectory::class);
    $assignments = app(AssignmentContextReader::class);

    $identity = $employees->findFor($this->insider, $this->employee->id);
    expect($identity?->employeeNumber)->toBe('CR-E1')
        ->and(array_keys(get_object_vars($identity)))->not->toContain('nationalId', 'phone')
        ->and($employees->findFor($this->outsider, $this->employee->id))->toBeNull()
        ->and($assignments->currentFor($this->employee->id)?->assignmentUuid)->toBe($this->current->id)
        ->and(collect($assignments->historyFor($this->employee->id))->pluck('assignmentUuid')->all())->toBe([$this->current->id, $this->oldAssignment->id])
        ->and($assignments->historyFor('nope'))->toBe([]);
});

it('verifies a card with the holder\'s minimal identity and keeps the three identifiers apart', function (): void {
    $card = IdCard::query()->create(['employee_id' => $this->employee->id, 'card_number' => 'CR-C1', 'status' => CardStatus::Active->value, 'is_current' => true, 'expires_at' => now()->addYear()]);
    app(CardQrPayloadService::class)->ensurePublicReference($card);
    $card->refresh();
    $verification = app(CredentialVerification::class);

    $answer = $verification->verifyCardUuid($card->public_card_uuid);
    expect($answer->valid)->toBeTrue()
        ->and($answer->cardUuid)->toBe($card->public_card_uuid)
        ->and($answer->employeeUuid)->toBe($this->employee->id)
        ->and($answer->cardUuid)->not->toBe($answer->employeeUuid)
        ->and($answer->employeeNumber)->toBe('CR-E1')
        ->and($answer->organizationUuid)->toBe($this->bureau->id)
        ->and(json_encode(get_object_vars($answer)))->not->toContain('1234567890123456')->not->toContain('0911000000');

    expect($verification->verify($card->public_card_uuid, CredentialFormat::PublicReference)->valid)->toBeTrue();

    $card->update(['status' => CardStatus::Revoked->value, 'revoked_at' => now()]);
    expect($verification->verifyCardUuid($card->public_card_uuid))->toMatchArray(['valid' => false, 'status' => 'revoked'])
        ->and($verification->verify('unknown-value')->status)->toBe('not_found');
});

it('searches active organizations by code prefix or name, inside the viewer\'s scope (Stage 3 picker read)', function (): void {
    $directory = app(OrganizationDirectory::class);

    expect(collect($directory->searchActiveFor($this->insider, 'CR-B'))->pluck('code')->all())->toBe(['CR-BUREAU'])
        ->and(collect($directory->searchActiveFor($this->insider, 'ተቋም'))->pluck('code')->all())->toBe(['CR-BUREAU'])
        ->and($directory->searchActiveFor($this->outsider, 'CR-B'))->toBe([])
        ->and(collect($directory->searchActiveFor($this->insider, 'CR-CLOSED'))->all())->toBe([]);
});
