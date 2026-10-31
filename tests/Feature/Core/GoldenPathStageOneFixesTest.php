<?php

declare(strict_types=1);

use App\Actions\Employees\RegisterEmployeeAction;
use App\Actions\Positions\UpdatePositionAction;
use App\Backbone\Commands\EndAssignment;
use App\Backbone\Commands\StartAssignment;
use App\Backbone\Commands\TransferAssignment;
use App\Enums\AssignmentStatus;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\ExternalApplication;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\PositionOccupancy;
use App\Models\User;
use App\Support\Security\NationalIdHash;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Entitlements\Application\Actions\ServiceTransactions\VerifyCardForServiceAction;
use Modules\Entitlements\Domain\Models\ServiceType;
use Modules\Transfer\Contracts\Data\TransferApplicationStatus;
use Modules\Transfer\Domain\Models\TransferApplication;
use Spatie\Permission\Models\Permission;

/*
 * Golden Path Stage 1 re-audit (code-verified gaps): master writes keep their
 * data and rules whatever the form or the caller sends.
 */

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-21 09:00'));
    $type = OrganizationType::query()->create(['code' => 'GP1-T', 'name_en' => 'Stage one type']);
    $this->organization = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'GP1-ORG', 'name_en' => 'Stage one bureau', 'status' => 'active']);
    $this->other = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'GP1-ORG2', 'name_en' => 'Second bureau', 'status' => 'active']);
    $this->position = Position::query()->create(['organization_id' => $this->organization->id, 'job_position_code' => 'GP1-P', 'title_en' => 'Officer', 'is_active' => true]);

    $this->employee = Employee::query()->create([
        'employee_number' => 'GP1-E1', 'first_name' => 'Abeba', 'last_name' => 'Kebede', 'full_name' => 'Abeba Kebede',
        'national_id' => '1111222233334444', 'national_id_hash' => NationalIdHash::of('1111222233334444'),
        'status' => EmployeeStatus::Active->value,
    ]);
    $this->assignment = EmployeeAssignment::query()->create([
        'employee_id' => $this->employee->id, 'organization_id' => $this->organization->id, 'position_id' => $this->position->id,
        'is_current' => true, 'assignment_status' => AssignmentStatus::Active->value, 'effective_from' => '2025-03-01',
    ]);
    $this->employee->forceFill(['current_assignment_id' => $this->assignment->id])->save();

    foreach (['employees.view', 'employees.update', 'employees.manage', 'employees.viewPii'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
});

function stageOneEditor(bool $seesPii): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(array_merge(['employees.view', 'employees.update', 'employees.manage'], $seesPii ? ['employees.viewPii'] : []));

    return $user;
}

it('keeps the national id when an editor who cannot see it saves the form', function (): void {
    // The edit form shows such an editor a blank national ID; saving must not erase it.
    $this->actingAs(stageOneEditor(false))
        ->patch(route('employees.update', $this->employee), [
            'first_name' => 'Abeba', 'last_name' => 'Kebede', 'status' => 'active', 'national_id' => '',
        ])->assertSessionHasNoErrors();

    $employee = $this->employee->fresh();
    expect($employee->national_id)->toBe('1111222233334444')
        ->and($employee->national_id_hash)->toBe(NationalIdHash::of('1111222233334444'));
});

it('still lets an editor who sees the national id change it', function (): void {
    $this->actingAs(stageOneEditor(true))
        ->patch(route('employees.update', $this->employee), [
            'first_name' => 'Abeba', 'last_name' => 'Kebede', 'status' => 'active', 'national_id' => '5555666677778888',
        ])->assertSessionHasNoErrors();

    expect($this->employee->fresh()->national_id)->toBe('5555666677778888');
});

it('refuses to transfer an ended placement, which would seat a separated employee again', function (): void {
    DB::transaction(function (): void {
        app(EndAssignment::class)->execute($this->employee, Carbon::parse('2026-10-21'), 'terminated');
    });

    expect(fn () => DB::transaction(fn () => app(TransferAssignment::class)->execute(
        $this->employee->fresh(),
        $this->assignment->fresh(),
        ['organization_id' => $this->other->id, 'organization_unit_id' => null, 'position_id' => null, 'hierarchy_version_id' => null, 'job_description_id' => null],
        Carbon::parse('2026-11-01'),
        'transferred',
        'transferred_in',
    )))->toThrow(LogicException::class);

    expect(EmployeeAssignment::query()->where('employee_id', $this->employee->id)->count())->toBe(1)
        ->and($this->employee->fresh()->current_assignment_id)->toBe($this->assignment->id);
});

it('refuses to deactivate or move a position while it is held', function (): void {
    $actor = User::factory()->create();
    $update = fn (array $attributes) => app(UpdatePositionAction::class)->execute($this->position, $attributes, $actor);

    expect(fn () => $update(['is_active' => false]))->toThrow(ValidationException::class)
        ->and(fn () => $update(['organization_id' => $this->other->id]))->toThrow(ValidationException::class)
        ->and(fn () => $update(['effective_to' => '2026-10-21']))->toThrow(ValidationException::class);

    expect($this->position->fresh()->is_active)->toBeTrue()
        ->and($this->position->fresh()->organization_id)->toBe($this->organization->id);

    // Renaming an occupied position is still allowed.
    expect($update(['title_en' => 'Senior Officer'])->title_en)->toBe('Senior Officer');
});

it('lets a vacant position be deactivated', function (): void {
    $this->assignment->update(['assignment_status' => AssignmentStatus::Closed->value]);

    $position = app(UpdatePositionAction::class)->execute($this->position, ['is_active' => false], User::factory()->create());

    expect($position->is_active)->toBeFalse();
});

it('never lets an applicant take a workflow decision on their own transfer application', function (): void {
    foreach (['transfers.applications.view', 'transfers.applications.screen', 'transfers.approve', 'transfers.reject', 'transfers.complete'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $staff = User::factory()->create(['employee_id' => $this->employee->id]);
    $staff->givePermissionTo(['transfers.applications.view', 'transfers.applications.screen', 'transfers.approve', 'transfers.reject', 'transfers.complete']);

    $own = new TransferApplication([
        'employee_id' => $this->employee->id,
        'releasing_organization_id' => $this->organization->id,
        'receiving_organization_id' => $this->other->id,
        'status' => TransferApplicationStatus::Submitted->value,
    ]);

    expect($staff->can('view', $own))->toBeTrue()
        ->and($staff->can('screen', $own))->toBeFalse()
        ->and($staff->can('reject', $own))->toBeFalse();

    $someoneElse = new TransferApplication([
        'employee_id' => Employee::query()->create(['employee_number' => 'GP1-E2', 'first_name' => 'Other', 'last_name' => 'Person', 'full_name' => 'Other Person', 'status' => 'active'])->id,
        'releasing_organization_id' => $this->organization->id,
        'receiving_organization_id' => $this->other->id,
        'status' => TransferApplicationStatus::Submitted->value,
    ]);

    expect($staff->can('screen', $someoneElse))->toBeTrue();
});

it('bounds card verification for a service to the application organizations', function (): void {
    $card = IdCard::query()->create([
        'employee_id' => $this->employee->id, 'card_number' => 'GP1-CARD', 'status' => CardStatus::Active->value,
        'is_current' => true, 'issued_at' => now()->subMonth(), 'expires_at' => now()->addYear(),
    ]);
    $serviceType = ServiceType::query()->create(['code' => 'gp1-service', 'name_en' => 'Stage one service']);
    $verify = function (ExternalApplication $application) use ($card, $serviceType): array {
        $request = Request::create('/api/v1/cards/verify', 'POST');
        $request->setUserResolver(fn () => $application);

        return app(VerifyCardForServiceAction::class)
            ->verifyResolvedCard($card->fresh(), $serviceType, null, null, $request);
    };
    $application = fn (string $access) => ExternalApplication::query()->create([
        'name' => 'Stage one partner', 'code' => 'GP1-'.Str::random(6), 'status' => 'active',
        'allowed_scopes' => ['id_cards.verify'], 'rate_limit_per_minute' => 100, 'organization_access' => $access,
    ]);

    $outside = $application('scoped');
    $outside->organizationScopes()->create(['organization_id' => $this->other->id, 'scope_type' => 'self']);
    $denied = $verify($outside);
    expect($denied['allowed'])->toBeFalse()
        ->and($denied['result_code'])->toBe('outside_organization_scope')
        ->and($denied['card_status'])->toBeNull();

    $inside = $application('scoped');
    $inside->organizationScopes()->create(['organization_id' => $this->organization->id, 'scope_type' => 'self']);
    expect($verify($inside)['result_code'])->not->toBe('outside_organization_scope')
        ->and($verify($application('city'))['result_code'])->not->toBe('outside_organization_scope');
});

it('records a registered placement on the establishment ledger and holds it again when employment resumes', function (): void {
    $position = Position::query()->create(['organization_id' => $this->organization->id, 'job_position_code' => 'GP1-LEDGER', 'title_en' => 'Ledger officer', 'is_active' => true]);
    PositionEstablishment::query()->create(['establishment_number' => 'GP1-EST', 'organization_id' => $this->organization->id, 'position_id' => $position->id, 'approved_slots' => 1, 'effective_from' => '2025-01-01', 'status' => 'approved']);

    $employee = app(RegisterEmployeeAction::class)->execute(
        ['employee_number' => 'GP1-LEDGER-E', 'first_name' => 'Lidya', 'last_name' => 'Alemu', 'full_name' => 'Lidya Alemu', 'status' => EmployeeStatus::Active],
        ['organization_id' => $this->organization->id, 'position_id' => $position->id, 'effective_from' => '2026-10-21'],
        User::factory()->create(),
    );
    $activeRows = fn () => PositionOccupancy::query()->where('employee_id', $employee->id)->where('status', 'active')->count();

    expect($activeRows())->toBe(1);

    DB::transaction(fn () => app(EndAssignment::class)->execute($employee->fresh(), Carbon::parse('2026-10-21'), 'terminated'));
    expect($activeRows())->toBe(0);

    DB::transaction(fn () => app(StartAssignment::class)->execute($employee->fresh(), Carbon::parse('2026-10-21'), 'reinstated'));
    expect($activeRows())->toBe(1);
});
