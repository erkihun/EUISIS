<?php

declare(strict_types=1);

use App\Actions\IdCards\ReinstateCardAction;
use App\Enums\AssignmentStatus;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use Spatie\Permission\Models\Permission;

/*
 * Suspension is a reversible block on a card (Golden Path Stage 1,
 * SuspendCardAction): services refuse a suspended card; reinstating it
 * makes it active again under the activation rules.
 */

beforeEach(function (): void {
    $type = OrganizationType::query()->create(['code' => 'SUS-T', 'name_en' => 'Suspension type']);
    $organization = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'SUS-ORG', 'name_en' => 'Suspension bureau', 'status' => 'active']);
    $this->employee = Employee::query()->create(['employee_number' => 'SUS-E1', 'first_name' => 'Hana', 'last_name' => 'Girma', 'full_name' => 'Hana Girma', 'status' => EmployeeStatus::Active->value]);
    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $this->employee->id, 'organization_id' => $organization->id,
        'is_current' => true, 'assignment_status' => AssignmentStatus::Active->value, 'effective_from' => '2025-01-01',
    ]);
    $this->employee->forceFill(['current_assignment_id' => $assignment->id])->save();
    $this->card = IdCard::query()->create([
        'employee_id' => $this->employee->id, 'card_number' => 'SUS-CARD-1', 'status' => CardStatus::Active->value,
        'is_current' => true, 'issued_at' => now()->subMonth(), 'expires_at' => now()->addYear(),
    ]);

    foreach (['id-cards.view', 'id-cards.revoke', 'id-cards.activate'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $this->officer = User::factory()->create();
    $this->officer->givePermissionTo(['id-cards.view', 'id-cards.revoke', 'id-cards.activate']);
});

it('suspends an active card and reinstates it, auditing both with the reason', function (): void {
    $this->actingAs($this->officer)
        ->post(route('id-cards.suspend', $this->card), ['reason' => 'Under investigation'])
        ->assertSessionHasNoErrors()->assertRedirect();

    expect($this->card->fresh()->status)->toBe(CardStatus::Suspended)
        ->and(AuditLog::query()->where('event_type', 'card_suspended')->where('auditable_id', $this->card->id)->value('reason'))->toBe('Under investigation');

    $this->actingAs($this->officer)
        ->post(route('id-cards.reinstate', $this->card), ['reason' => 'Investigation closed'])
        ->assertSessionHasNoErrors()->assertRedirect();

    expect($this->card->fresh()->status)->toBe(CardStatus::Active)
        ->and(AuditLog::query()->where('event_type', 'card_reinstated')->where('auditable_id', $this->card->id)->exists())->toBeTrue();
});

it('requires a reason to suspend', function (): void {
    $this->actingAs($this->officer)
        ->post(route('id-cards.suspend', $this->card), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect($this->card->fresh()->status)->toBe(CardStatus::Active);
});

it('refuses suspension without the permission and outside the allowed statuses', function (): void {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('id-cards.view');
    $this->actingAs($viewer)->post(route('id-cards.suspend', $this->card), ['reason' => 'No right to do this'])->assertForbidden();

    $this->card->update(['status' => CardStatus::Revoked->value]);
    $this->actingAs($this->officer)->post(route('id-cards.suspend', $this->card), ['reason' => 'Already revoked card'])->assertForbidden();
    $this->actingAs($this->officer)->post(route('id-cards.reinstate', $this->card), ['reason' => 'Not suspended at all'])->assertForbidden();
});

it('does not reinstate a card while the employee holds another active card', function (): void {
    $this->card->update(['status' => CardStatus::Suspended->value]);
    IdCard::query()->create([
        'employee_id' => $this->employee->id, 'card_number' => 'SUS-CARD-2', 'status' => CardStatus::Active->value,
        'is_current' => false, 'issued_at' => now(), 'expires_at' => now()->addYear(),
    ]);

    expect(fn () => app(ReinstateCardAction::class)->execute($this->card, $this->officer, 'Second card exists'))
        ->toThrow(DomainException::class);
    expect($this->card->fresh()->status)->toBe(CardStatus::Suspended);
});

it('records the caller IP and user agent on audit rows written by Core actions', function (): void {
    $this->actingAs($this->officer)
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->withHeader('User-Agent', 'CoreAuditTest/1.0')
        ->post(route('id-cards.suspend', $this->card), ['reason' => 'Audit context check'])
        ->assertSessionHasNoErrors();

    $row = AuditLog::query()->where('event_type', 'card_suspended')->where('auditable_id', $this->card->id)->firstOrFail();
    expect($row->request_ip)->toBe('203.0.113.7')
        ->and($row->user_agent)->toBe('CoreAuditTest/1.0');
});
