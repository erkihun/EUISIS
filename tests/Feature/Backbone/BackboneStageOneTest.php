<?php

declare(strict_types=1);

use App\Actions\IdCards\ApproveCardRequestAction;
use App\Actions\IdCards\ReplaceCardAction;
use App\Backbone\Adapters\CardBasedServiceEligibility;
use App\Backbone\Adapters\ClosurePathOrganizationHierarchy;
use App\Backbone\Adapters\EloquentAssignmentContextReader;
use App\Backbone\Adapters\EloquentEmployeeDirectory;
use App\Backbone\Adapters\IdCardCredentialStatus;
use App\Backbone\Commands\TransferAssignment;
use App\Backbone\Contracts\AssignmentContextReader;
use App\Backbone\Contracts\CardCredentialStatus;
use App\Backbone\Contracts\EmployeeDirectory;
use App\Backbone\Contracts\OrganizationHierarchy;
use App\Backbone\Contracts\ServiceEligibility;
use App\Enums\AssignmentStatus;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Models\CardReplacement;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\HierarchyVersion;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationClosurePath;
use App\Models\OrganizationType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Stage 1 of the master backbone separation (docs/architecture/backbone-refactor-migration.md):
 * contracts and adapters, the shared transfer command and credential lineage.
 */

function backboneOrganization(string $code): Organization
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'BB-T'], ['name_en' => 'Backbone type']);

    return Organization::query()->firstOrCreate(['code' => $code], ['organization_type_id' => $type->id, 'name_en' => "Org {$code}", 'status' => 'active']);
}

/** @return array{0: Employee, 1: EmployeeAssignment, 2: IdCard, 3: HierarchyVersion} */
function backboneEmployee(string $orgCode = 'BB-A'): array
{
    $version = HierarchyVersion::query()->firstOrCreate(['version_name' => 'bb-v1'], ['status' => 'published', 'effective_from' => now()->subYear()->toDateString()]);
    $employee = Employee::query()->create([
        'employee_number' => 'BB-'.Str::upper(Str::random(8)), 'first_name' => 'Back', 'last_name' => 'Bone',
        'full_name' => 'Back Bone', 'name_en' => 'Back Bone', 'status' => EmployeeStatus::Active,
    ]);
    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id, 'organization_id' => backboneOrganization($orgCode)->id, 'hierarchy_version_id' => $version->id,
        'assignment_status' => AssignmentStatus::Active, 'effective_from' => now()->subYear()->toDateString(), 'is_current' => true,
    ]);
    $employee->update(['current_assignment_id' => $assignment->id]);
    $card = IdCard::query()->create([
        'employee_id' => $employee->id, 'card_number' => 'BBC-'.Str::upper(Str::random(8)), 'status' => CardStatus::Active,
        'expires_at' => now()->addYear(), 'token_version' => 0, 'is_current' => true,
        'public_card_uuid' => (string) Str::uuid(), 'qr_status' => 'active',
    ]);

    return [$employee->fresh(), $assignment->fresh(), $card->fresh(), $version];
}

it('binds every backbone contract to its adapter', function (): void {
    expect(app(EmployeeDirectory::class))->toBeInstanceOf(EloquentEmployeeDirectory::class)
        ->and(app(AssignmentContextReader::class))->toBeInstanceOf(EloquentAssignmentContextReader::class)
        ->and(app(OrganizationHierarchy::class))->toBeInstanceOf(ClosurePathOrganizationHierarchy::class)
        ->and(app(CardCredentialStatus::class))->toBeInstanceOf(IdCardCredentialStatus::class)
        ->and(app(ServiceEligibility::class))->toBeInstanceOf(CardBasedServiceEligibility::class);
});

it('keeps employee UUID, employee number, card UUID, card number and user id apart', function (): void {
    [$employee, , $card] = backboneEmployee();
    $user = User::factory()->create(['employee_id' => $employee->id]);

    $identity = app(EmployeeDirectory::class)->findByUuid($employee->id);
    $credential = app(CardCredentialStatus::class)->forCardUuid($card->public_card_uuid);

    expect($identity->employeeUuid)->toBe((string) $employee->id)
        ->and($identity->employeeNumber)->toBe($employee->employee_number)
        ->and(array_unique([$identity->employeeUuid, (string) $user->id, $credential->cardUuid, $credential->cardRecordUuid]))->toHaveCount(4)
        ->and($credential->cardNumber)->not->toBe($identity->employeeNumber)
        ->and($credential->employeeUuid)->toBe($identity->employeeUuid)
        ->and(app(EmployeeDirectory::class)->findByNumber($employee->employee_number)?->employeeUuid)->toBe($identity->employeeUuid)
        ->and(app(EmployeeDirectory::class)->findByUuid('not-a-uuid'))->toBeNull()
        ->and(app(EmployeeDirectory::class)->findByUuid((string) $user->id))->toBeNull();
});

it('reads the current assignment context', function (): void {
    [$employee, $assignment] = backboneEmployee();

    $context = app(AssignmentContextReader::class)->currentFor($employee->id);

    expect($context->assignmentUuid)->toBe((string) $assignment->id)
        ->and($context->employeeUuid)->toBe((string) $employee->id)
        ->and($context->organizationUuid)->toBe((string) $assignment->organization_id)
        ->and($context->isCurrent)->toBeTrue()
        ->and($context->status)->toBe(AssignmentStatus::Active->value);
});

it('applies one credential rule: active, not revoked, not expired, QR active and current', function (): void {
    [, , $card] = backboneEmployee();
    $status = app(CardCredentialStatus::class);

    expect($status->evaluate($card))->valid->toBeTrue()->status->toBe('active');

    $card->qr_status = 'revoked';
    expect($status->evaluate($card))->valid->toBeFalse()->status->toBe('revoked');

    $card->qr_status = 'active';
    $card->expires_at = now()->subDay();
    expect($status->evaluate($card))->valid->toBeFalse()->status->toBe('expired');

    $card->expires_at = now()->addYear();
    $card->is_current = false;
    expect($status->evaluate($card))->valid->toBeFalse()->status->toBe('replaced');

    expect($status->forCardUuid((string) Str::uuid()))->toBeNull()
        ->and($status->forCardUuid('../etc'))->toBeNull();
});

it('answers hierarchy questions from the closure paths of the version in force', function (): void {
    [, , , $version] = backboneEmployee();
    $parent = backboneOrganization('BB-P');
    $child = backboneOrganization('BB-C');
    OrganizationClosurePath::query()->create(['hierarchy_version_id' => $version->id, 'ancestor_organization_id' => $parent->id, 'descendant_organization_id' => $child->id, 'depth' => 1]);
    $hierarchy = app(OrganizationHierarchy::class);

    expect($hierarchy->isSameOrDescendant($child->id, $parent->id))->toBeTrue()
        ->and($hierarchy->isSameOrDescendant($parent->id, $child->id))->toBeFalse()
        ->and($hierarchy->isSameOrDescendant($parent->id, $parent->id))->toBeTrue()
        ->and($hierarchy->subtreeIds($parent->id))->toBe([(string) $parent->id, (string) $child->id]);
});

it('gates service delivery on the employee and the current card', function (): void {
    [$employee, , $card] = backboneEmployee();
    $eligibility = app(ServiceEligibility::class);

    expect($eligibility->check($employee->id, 'cafeteria'))->eligible->toBeTrue();

    $card->update(['status' => CardStatus::Suspended]);
    expect($eligibility->check($employee->id, 'cafeteria'))->eligible->toBeFalse()->reasonCode->toBe('id_card_suspended');

    expect($eligibility->check((string) Str::uuid(), 'cafeteria'))->eligible->toBeFalse();
});

it('transfers the assignment without touching the employee identity or the card', function (): void {
    [$employee, $old, $card, $version] = backboneEmployee();
    $destination = backboneOrganization('BB-DEST');
    $effective = Carbon::parse('2026-11-01');

    $new = DB::transaction(fn () => app(TransferAssignment::class)->execute(
        $employee, $old,
        ['organization_id' => $destination->id, 'organization_unit_id' => null, 'position_id' => null, 'hierarchy_version_id' => $version->id, 'job_description_id' => null],
        $effective, 'Transfer out', 'Transfer in',
    ));

    $employee->refresh();
    $old->refresh();
    $card->refresh();

    expect($employee->current_assignment_id)->toBe($new->id)
        ->and($new->employee_id)->toBe($employee->id)
        ->and($new->organization_id)->toBe($destination->id)
        ->and($new->effective_from->toDateString())->toBe('2026-11-01')
        ->and($old->is_current)->toBeFalse()
        ->and($old->assignment_status)->toBe(AssignmentStatus::Closed)
        ->and($old->effective_to->toDateString())->toBe('2026-10-31')
        ->and(EmployeeAssignment::query()->where('employee_id', $employee->id)->where('is_current', true)->count())->toBe(1)
        ->and(Employee::query()->count())->toBe(1)
        ->and($card->employee_id)->toBe($employee->id)
        ->and($card->is_current)->toBeTrue()
        ->and($card->status)->toBe(CardStatus::Active);
});

it('refuses to close another employee\'s assignment', function (): void {
    // The outside-a-transaction guard cannot be exercised here: RefreshDatabase
    // already wraps every test in a transaction.
    [$employee, $old] = backboneEmployee();
    [, $foreign] = backboneEmployee();
    $placement = ['organization_id' => $old->organization_id, 'organization_unit_id' => null, 'position_id' => null, 'hierarchy_version_id' => null, 'job_description_id' => null];

    expect(fn () => DB::transaction(fn () => app(TransferAssignment::class)->execute($employee, $foreign, $placement, now(), 'a', 'b')))->toThrow(LogicException::class);
    expect($foreign->fresh()->is_current)->toBeTrue()
        ->and($old->fresh()->is_current)->toBeTrue();
});

it('links a replacement card to the card it replaced, on the card and on the replacement record', function (): void {
    [$employee, , $card] = backboneEmployee();
    $card->update(['status' => CardStatus::Lost]);
    $requester = User::factory()->create();
    $approver = User::factory()->create();

    $request = app(ReplaceCardAction::class)->execute($card->fresh(), $requester, 'Lost on the bus');
    expect(CardReplacement::query()->where('old_card_id', $card->id)->value('new_card_id'))->toBe($card->id);

    $replacement = app(ApproveCardRequestAction::class)->execute($request, $approver)['card'];
    $old = $card->fresh();

    expect(CardReplacement::query()->where('old_card_id', $card->id)->value('new_card_id'))->toBe($replacement->id)
        ->and($replacement->previous_card_id)->toBe($card->id)
        ->and($replacement->employee_id)->toBe($employee->id)
        ->and($replacement->card_number)->not->toBe($old->card_number)
        ->and($old->status)->toBe(CardStatus::Replaced)
        ->and($old->is_current)->toBeFalse()
        ->and(Employee::query()->count())->toBe(1);
});

it('reports unlinked historical replacement records and repairs them only when asked', function (): void {
    [, , $card] = backboneEmployee();
    $card->update(['status' => CardStatus::Replaced, 'is_current' => false]);
    $successor = IdCard::query()->create([
        'employee_id' => $card->employee_id, 'card_number' => 'BBC-NEXT', 'status' => CardStatus::PendingPrint,
        'previous_card_id' => $card->id, 'token_version' => 0, 'is_current' => true, 'expires_at' => now()->addYear(),
    ]);
    $record = CardReplacement::query()->create(['old_card_id' => $card->id, 'new_card_id' => $card->id, 'reason' => 'lost', 'replaced_at' => now()]);

    $this->artisan('cards:audit-replacement-links')->expectsOutputToContain('Unlinked replacement records: 1 (linkable: 1')->assertSuccessful();
    expect($record->fresh()->new_card_id)->toBe($card->id);

    $this->artisan('cards:audit-replacement-links', ['--repair' => true])->expectsOutputToContain('Linked 1 record(s).')->assertSuccessful();
    expect($record->fresh()->new_card_id)->toBe($successor->id);
});
