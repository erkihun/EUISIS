<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Enums\HierarchyVersionStatus;
use App\Models\ApiEndpointDefinition;
use App\Models\AuditLog;
use App\Models\CardVerification;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\ExternalApplication;
use App\Models\HierarchyVersion;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationClosurePath;
use App\Models\OrganizationType;
use App\Services\ApiEndpointCatalogService;
use App\Services\IdCards\CardQrPayloadService;
use Illuminate\Support\Str;

/*
 * Stage 4 of the master backbone separation: assignment collection and
 * history, card status by card UUID, explicit verification records, and audit
 * rows that carry the request's correlation id. Every read is bounded to the
 * caller's organizations; outside the bound a record is reported as unknown.
 */

beforeEach(function (): void {
    app(ApiEndpointCatalogService::class)->sync();
    $type = OrganizationType::query()->create(['code' => 'S4-TYPE', 'name_en' => 'Stage four type']);
    $make = fn (string $code): Organization => Organization::query()->create(['organization_type_id' => $type->id, 'code' => $code, 'name_en' => 'Org '.$code, 'status' => 'active']);
    $this->mine = $make('S4-MINE');
    $this->other = $make('S4-OTHER');
    $version = HierarchyVersion::query()->create(['version_name' => 'S4 v1', 'status' => HierarchyVersionStatus::Published->value, 'effective_from' => now()->subMonth()->toDateString()]);
    foreach ([$this->mine, $this->other] as $organization) {
        OrganizationClosurePath::query()->create(['hierarchy_version_id' => $version->id, 'ancestor_organization_id' => $organization->id, 'descendant_organization_id' => $organization->id, 'depth' => 0]);
    }
    [$this->employee, $this->current] = s4Employee($this->mine);
    [$this->outsider] = s4Employee($this->other);
});

/** @return array{0: Employee, 1: EmployeeAssignment} */
function s4Employee(Organization $organization): array
{
    $employee = Employee::query()->create(['employee_number' => 'S4-'.Str::upper(Str::random(6)), 'first_name' => 'Stage', 'last_name' => 'Four', 'full_name' => 'Stage Four', 'status' => EmployeeStatus::Active->value]);
    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id, 'organization_id' => $organization->id, 'is_current' => true,
        'assignment_status' => AssignmentStatus::Active->value, 'effective_from' => now()->subYear()->toDateString(),
    ]);
    $employee->forceFill(['current_assignment_id' => $assignment->id])->save();

    return [$employee->fresh(), $assignment];
}

function s4Card(Employee $employee, array $overrides = []): IdCard
{
    $card = IdCard::query()->create(['employee_id' => $employee->id, 'card_number' => 'S4C-'.Str::upper(Str::random(6)), 'status' => CardStatus::Active->value, 'is_current' => true, 'expires_at' => now()->addYear(), ...$overrides]);
    app(CardQrPayloadService::class)->ensurePublicReference($card);

    return $card->refresh();
}

/** @return array{0: ExternalApplication, 1: string} an application bound to one organization */
function s4Application(array $scopes, Organization $organization): array
{
    $application = ExternalApplication::query()->create([
        'name' => 'Stage four partner', 'code' => 'S4-'.Str::random(6), 'status' => 'active', 'allowed_scopes' => $scopes,
        'rate_limit_per_minute' => 500, 'organization_access' => 'scoped',
    ]);
    $application->organizationScopes()->create(['organization_id' => $organization->id, 'scope_type' => 'self']);
    $ids = ApiEndpointDefinition::query()->whereIn('required_scope', $scopes)->pluck('id')->all();
    $application->endpoints()->sync(collect($ids)->mapWithKeys(fn (string $id): array => [$id => ['id' => (string) Str::uuid(), 'is_enabled' => true]])->all());

    return [$application, $application->createToken('s4', $scopes)->plainTextToken];
}

it('lists current assignments inside the bound, and history only on request', function (): void {
    [, $token] = s4Application(['employee_assignments.read'], $this->mine);
    // A closed earlier placement of the same employee, in the same organization.
    EmployeeAssignment::query()->create([
        'employee_id' => $this->employee->id, 'organization_id' => $this->mine->id, 'is_current' => false,
        'assignment_status' => AssignmentStatus::Closed->value, 'effective_from' => now()->subYears(3)->toDateString(), 'effective_to' => now()->subYear()->subDay()->toDateString(),
    ]);

    $current = $this->withToken($token)->getJson('/api/v1/assignments')->assertOk()->json('data');
    expect(collect($current)->pluck('id')->all())->toBe([$this->current->id]);

    $all = $this->withToken($token)->getJson('/api/v1/assignments?current=false')->assertOk()->json('data');
    expect($all)->toHaveCount(2)
        ->and(collect($all)->pluck('employee_id')->unique()->all())->toBe([$this->employee->id])
        ->and(array_keys($all[0]))->toBe(['id', 'employee_id', 'organization_id', 'organization_unit_id', 'position_id', 'assignment_status', 'is_current', 'effective_from', 'effective_to']);
});

it('returns an employee\'s placement history newest first, and nothing outside the bound', function (): void {
    [, $token] = s4Application(['employee_assignments.read'], $this->mine);
    // An earlier placement in the other organization stays hidden from this caller.
    EmployeeAssignment::query()->create([
        'employee_id' => $this->employee->id, 'organization_id' => $this->other->id, 'is_current' => false,
        'assignment_status' => AssignmentStatus::Closed->value, 'effective_from' => now()->subYears(3)->toDateString(), 'effective_to' => now()->subYear()->subDay()->toDateString(),
    ]);

    $history = $this->withToken($token)->getJson('/api/v1/employees/'.$this->employee->id.'/assignments')->assertOk()->json('data');
    expect(collect($history)->pluck('organization_id')->all())->toBe([$this->mine->id]);

    $this->withToken($token)->getJson('/api/v1/employees/'.$this->outsider->id.'/assignments')->assertNotFound();
});

it('reads a card\'s credential status and lineage by card UUID, and hides cards outside the bound', function (): void {
    [, $token] = s4Application(['id_cards.verify'], $this->mine);
    $old = s4Card($this->employee, ['status' => CardStatus::Replaced->value, 'is_current' => false]);
    $new = s4Card($this->employee, ['previous_card_id' => $old->id]);
    $outside = s4Card($this->outsider);

    $this->withToken($token)->getJson('/api/v1/cards/'.$new->public_card_uuid)->assertOk()
        ->assertJsonPath('data.valid', true)
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.employee_uuid', $this->employee->id)
        ->assertJsonPath('data.replaces_card_uuid', $old->public_card_uuid)
        ->assertJsonMissingPath('data.employee.full_name');
    $this->withToken($token)->getJson('/api/v1/cards/'.$old->public_card_uuid)->assertOk()
        ->assertJsonPath('data.valid', false)
        ->assertJsonPath('data.status', 'replaced')
        ->assertJsonPath('data.replaced_by_card_uuid', $new->public_card_uuid);

    $unknown = $this->withToken($token)->getJson('/api/v1/cards/'.Str::uuid())->assertNotFound()->json('error_code');
    expect($this->withToken($token)->getJson('/api/v1/cards/'.$outside->public_card_uuid)->assertNotFound()->json('error_code'))->toBe($unknown);
});

it('keeps an explicit verification record that only its maker can read back', function (): void {
    [$application, $token] = s4Application(['id_cards.verify'], $this->mine);
    [, $otherToken] = s4Application(['id_cards.verify'], $this->mine);
    $card = s4Card($this->employee);

    $created = $this->withToken($token)->postJson('/api/v1/verifications', ['card_uuid' => $card->public_card_uuid])->assertCreated()
        ->assertJsonPath('data.valid', true)->assertJsonPath('data.card_uuid', $card->public_card_uuid)->json('data');
    $record = CardVerification::query()->findOrFail($created['verification_id']);
    expect($record->api_client_id)->toBe($application->id)->and($record->allowed)->toBeTrue();

    $this->withToken($token)->getJson('/api/v1/verifications/'.$created['verification_id'])->assertOk()->assertJsonPath('data.verification_id', $created['verification_id']);
    $this->app['auth']->forgetGuards();
    $this->withToken($otherToken)->getJson('/api/v1/verifications/'.$created['verification_id'])->assertNotFound();
});

it('replays a retried verification with the same Idempotency-Key instead of recording it twice', function (): void {
    [, $token] = s4Application(['id_cards.verify'], $this->mine);
    $card = s4Card($this->employee);

    $first = $this->withToken($token)->withHeader('Idempotency-Key', 'verify-once-1')->postJson('/api/v1/verifications', ['card_uuid' => $card->public_card_uuid])->assertCreated()->json('data.verification_id');
    $second = $this->withToken($token)->withHeader('Idempotency-Key', 'verify-once-1')->postJson('/api/v1/verifications', ['card_uuid' => $card->public_card_uuid])->json('data.verification_id');

    expect($second)->toBe($first)->and(CardVerification::query()->count())->toBe(1);
});

it('writes audit rows with the request correlation id', function (): void {
    [, $token] = s4Application(['id_cards.verify'], $this->mine);
    $card = s4Card($this->employee);
    $id = (string) Str::uuid();

    $this->withToken($token)->withHeader('X-Request-ID', $id)->getJson('/api/v1/cards/'.$card->public_card_uuid)->assertOk()->assertHeader('X-Request-ID', $id);

    expect(AuditLog::query()->latest('created_at')->value('request_id'))->toBe($id);
});
