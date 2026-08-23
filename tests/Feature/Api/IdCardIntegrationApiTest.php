<?php

declare(strict_types=1);

use App\Actions\IdCards\GenerateCardTokenAction;
use App\Enums\AssignmentStatus;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\User;
use App\Services\IdCards\CardQrPayloadService;

/**
 * Integration API: QR safety, scope enforcement and card-status gating.
 */
beforeEach(function (): void {
    $type = OrganizationType::query()->create(['code' => 'API-TYPE', 'name_en' => 'Api Type']);

    $this->org = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'API-ORG',
        'name_en' => 'Api Organization',
        'status' => 'active',
    ]);

    $unit = OrganizationUnit::query()->create([
        'organization_id' => $this->org->id,
        'code' => 'API-U1',
        'name_en' => 'Api Unit',
        'unit_type' => 'department',
        'status' => 'active',
    ]);

    $position = Position::query()->create([
        'organization_id' => $this->org->id,
        'organization_unit_id' => $unit->id,
        'job_position_code' => 'API-P1',
        'title_en' => 'Api Position',
        'is_active' => true,
    ]);

    $this->employee = Employee::query()->create([
        'employee_number' => 'API-EMP-1',
        'first_name' => 'Abebe',
        'last_name' => 'Bekele',
        'full_name' => 'Abebe Bekele',
        'national_id' => '1234567890123456',
        'phone' => '0911000000',
        'status' => EmployeeStatus::Active->value,
    ]);

    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $this->employee->id,
        'organization_id' => $this->org->id,
        'organization_unit_id' => $unit->id,
        'position_id' => $position->id,
        'is_current' => true,
        'assignment_status' => AssignmentStatus::Active->value,
        'effective_from' => now()->toDateString(),
    ]);

    // currentAssignment() belongs to this column; without it the relation is
    // null and the verify response reports no organization.
    $this->employee->forceFill(['current_assignment_id' => $assignment->id])->save();

    $this->card = IdCard::query()->create([
        'employee_id' => $this->employee->id,
        'card_number' => 'API-CARD-1',
        'status' => CardStatus::Active->value,
        'is_current' => true,
        'issued_at' => now()->subMonth(),
        'expires_at' => now()->addYear(),
    ]);

    app(CardQrPayloadService::class)->ensurePublicReference($this->card);
    $this->card->refresh();
});

/** Token-authenticated caller carrying only the given abilities. */
function apiCaller(array $abilities): string
{
    $user = User::factory()->create();

    return $user->createToken('integration-test', $abilities)->plainTextToken;
}

it('encodes no personal data in the printed qr value', function (): void {
    $qr = app(CardQrPayloadService::class)->buildStableQrUrl($this->card);

    expect($qr)->toContain($this->card->public_card_uuid)
        ->and($qr)->not->toContain($this->employee->employee_number)
        ->and($qr)->not->toContain($this->employee->full_name)
        ->and($qr)->not->toContain($this->employee->national_id)
        ->and($qr)->not->toContain($this->employee->phone)
        ->and($qr)->not->toContain($this->card->card_number);
});

it('verifies an active card and returns only safe fields', function (): void {
    $response = $this->withToken(apiCaller(['id_cards.verify']))
        ->getJson(route('api.v1.id-cards.verify', $this->card->public_card_uuid))
        ->assertOk()
        ->assertJsonPath('valid', true)
        ->assertJsonPath('status', CardStatus::Active->value)
        ->assertJsonPath('employee.employee_number', 'API-EMP-1');

    $body = $response->getContent();

    foreach (['1234567890123456', '0911000000', 'national_id', 'salary'] as $sensitive) {
        expect($body)->not->toContain($sensitive);
    }
});

it('returns an absolute photo url so an integration on another host can load it', function (): void {
    $this->employee->forceFill(['photo_path' => 'employees/photos/test/photo.jpg'])->save();

    $response = $this->withToken(apiCaller(['id_cards.verify']))
        ->getJson(route('api.v1.id-cards.verify', $this->card->public_card_uuid))
        ->assertOk();

    $photo = $response->json('employee.photo_url');

    // A root-relative path would resolve against the cafeteria host and 404.
    expect($photo)->toStartWith('http')
        ->and($photo)->toContain('employees/photos/test/photo.jpg');
});

it('returns a null photo url when the employee has no photo', function (): void {
    $this->employee->forceFill(['photo_path' => null])->save();

    $this->withToken(apiCaller(['id_cards.verify']))
        ->getJson(route('api.v1.id-cards.verify', $this->card->public_card_uuid))
        ->assertOk()
        ->assertJsonPath('employee.photo_url', null);
});

it('returns the employee organization on a verified card', function (): void {
    // The cafeteria decides eligibility from this field. It silently came back
    // null because the eager-loaded employee select omitted the foreign key
    // currentAssignment() belongs to, so every scan was denied as
    // "organization unknown" despite a valid assignment.
    $this->withToken(apiCaller(['id_cards.verify']))
        ->getJson(route('api.v1.id-cards.verify', $this->card->public_card_uuid))
        ->assertOk()
        ->assertJsonPath('organization.code', $this->org->code)
        ->assertJsonPath('organization.name_en', $this->org->name_en)
        ->assertJsonPath('position.code', 'API-P1');
});

it('rejects a token that lacks the verify scope', function (): void {
    $this->withToken(apiCaller(['reports.read_limited']))
        ->getJson(route('api.v1.id-cards.verify', $this->card->public_card_uuid))
        ->assertForbidden()
        ->assertJsonPath('error_code', 'missing_scope')
        ->assertJsonPath('required_scope', 'id_cards.verify');
});

it('rejects an unauthenticated verification request', function (): void {
    $this->getJson(route('api.v1.id-cards.verify', $this->card->public_card_uuid))
        ->assertUnauthorized();
});

it('returns not found for an unknown card token', function (): void {
    $this->withToken(apiCaller(['id_cards.verify']))
        ->getJson(route('api.v1.id-cards.verify', '00000000-0000-4000-8000-000000000000'))
        ->assertNotFound()
        ->assertJsonPath('error_code', 'card_not_found');
});

it('reports an expired card as not valid', function (): void {
    $this->card->update(['expires_at' => now()->subDay()]);

    $this->withToken(apiCaller(['id_cards.verify']))
        ->getJson(route('api.v1.id-cards.verify', $this->card->public_card_uuid))
        ->assertOk()
        ->assertJsonPath('valid', false)
        ->assertJsonPath('status', CardStatus::Expired->value);
});

it('reports a revoked card as not valid', function (): void {
    $this->card->update(['status' => CardStatus::Revoked->value, 'revoked_at' => now()]);

    $this->withToken(apiCaller(['id_cards.verify']))
        ->getJson(route('api.v1.id-cards.verify', $this->card->public_card_uuid))
        ->assertOk()
        ->assertJsonPath('valid', false);
});

it('blocks service eligibility when the card is revoked', function (): void {
    $this->card->update(['status' => CardStatus::Revoked->value, 'revoked_at' => now()]);

    $this->withToken(apiCaller(['service_eligibility.check']))
        ->getJson(route('api.v1.employees.service-eligibility', $this->employee).'?service_type=cafeteria')
        ->assertForbidden()
        ->assertJsonPath('eligible', false)
        ->assertJsonPath('reason_code', 'id_card_revoked');
});

it('blocks service eligibility when the employee is inactive', function (): void {
    $this->employee->update(['status' => EmployeeStatus::Terminated->value]);

    $this->withToken(apiCaller(['service_eligibility.check']))
        ->getJson(route('api.v1.employees.service-eligibility', $this->employee).'?service_type=cafeteria')
        ->assertForbidden()
        ->assertJsonPath('eligible', false)
        ->assertJsonPath('reason_code', 'employee_inactive');
});

it('requires the eligibility scope for the eligibility endpoint', function (): void {
    $this->withToken(apiCaller(['id_cards.verify']))
        ->getJson(route('api.v1.employees.service-eligibility', $this->employee).'?service_type=cafeteria')
        ->assertForbidden()
        ->assertJsonPath('error_code', 'missing_scope');
});

it('still accepts legacy provider tokens so existing integrations keep working', function (): void {
    $this->withToken(apiCaller(['provider:access']))
        ->getJson(route('api.v1.id-cards.verify', $this->card->public_card_uuid))
        ->assertOk();
});

it('keeps the public card uuid stable across token rotation', function (): void {
    $original = $this->card->public_card_uuid;

    app(GenerateCardTokenAction::class)->execute($this->card);

    expect($this->card->fresh()->public_card_uuid)->toBe($original);
});

it('lists active organizations for an approved integration', function (): void {
    $response = $this->withToken(apiCaller(['reports.read_limited']))
        ->getJson(route('api.v1.organizations.index'))
        ->assertOk()
        ->assertJsonPath('data.0.code', 'API-ORG');

    // Directory data only — no employees or internal counts.
    $body = $response->getContent();
    foreach (['employee', 'national_id', 'salary'] as $forbidden) {
        expect($body)->not->toContain($forbidden);
    }
});

it('filters the organization directory by search term', function (): void {
    $this->withToken(apiCaller(['reports.read_limited']))
        ->getJson(route('api.v1.organizations.index').'?search=API-ORG')
        ->assertOk()
        ->assertJsonPath('data.0.code', 'API-ORG');

    $this->withToken(apiCaller(['reports.read_limited']))
        ->getJson(route('api.v1.organizations.index').'?search=nothing-matches-this')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('requires the reports scope for the organization directory', function (): void {
    $this->withToken(apiCaller(['id_cards.verify']))
        ->getJson(route('api.v1.organizations.index'))
        ->assertForbidden()
        ->assertJsonPath('error_code', 'missing_scope');
});

it('rejects an unauthenticated organization directory request', function (): void {
    $this->getJson(route('api.v1.organizations.index'))->assertUnauthorized();
});
