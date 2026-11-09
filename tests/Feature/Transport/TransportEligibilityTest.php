<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Models\EmployeeAssignment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Transport\Application\Notifications\TransportNotification;
use Modules\Transport\Application\Services\Boarding\BoardingRequest;
use Modules\Transport\Application\Services\Boarding\TransportBoardingService;
use Modules\Transport\Domain\Enums\TransportVerificationMethod;
use Modules\Transport\Domain\Models\TransportEligibility;
use Modules\Transport\Domain\Models\TransportException;
use Modules\Transport\Domain\Models\TransportTransaction;
use Tests\Support\TransportScenario;

/*
 * Eligible != assigned != actual rider. Grants decide who qualifies, the
 * pass (route assignment) decides the default route, and a transfer keeps
 * the employee and card identity while the assignment is re-evaluated.
 */
beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-14 07:15'));
    $this->s = TransportScenario::make();
    $this->provider = $this->s->provider();
    $this->route = $this->s->route($this->provider, ['assigned_organization_id' => $this->s->org['subcity_a']->id, 'assigned_scope_type' => 'subtree']);
    $this->trip = $this->s->trip($this->route);
    [$this->employee, $this->card] = $this->s->employee('woreda_a1');
    $this->manager = $this->s->portalUser($this->provider, 'manager');

    $this->board = function (?string $tripId = null) {
        return app(TransportBoardingService::class)->board(new BoardingRequest(
            providerId: $this->provider->id, source: BoardingRequest::SOURCE_PROVIDER_PORTAL, method: TransportVerificationMethod::Qr,
            credential: $this->s->qr($this->card), tripId: $tripId ?? $this->trip->id, idempotencyKey: (string) Str::uuid(), providerUser: portalActor($this->manager),
        ));
    };
});

it('boards on an organization grant without an individual assignment, and records that basis', function (): void {
    expect(($this->board)()->resultCode)->toBe('active_transport_pass_missing');

    $grant = $this->s->grant('subcity_a', 'subtree', ['effective_to' => '2026-10-20']);
    $decision = ($this->board)();
    expect($decision->accepted)->toBeTrue()
        ->and($decision->transaction->eligibility_snapshot['basis'])->toBe('organization_grant')
        ->and($decision->transaction->eligibility_snapshot['eligibility_id'])->toBe($grant->id)
        ->and($decision->transaction->transport_pass_id)->toBeNull();

    // Outside the grant's dates, or with grants switched off, the employee is back to needing an assignment.
    $this->travelTo(Carbon::parse('2026-10-21 07:15'));
    $later = $this->s->trip($this->route);
    expect(($this->board)($later->id)->resultCode)->toBe('active_transport_pass_missing');
});

it('does not let a sibling office grant or an employee grant for someone else qualify the employee', function (): void {
    $this->s->grant('subcity_b', 'subtree');
    [$other] = $this->s->employee('woreda_a1', 'Someone Else');
    TransportEligibility::query()->create(['subject_type' => 'employee', 'employee_id' => $other->id, 'status' => 'active', 'effective_from' => '2026-01-01']);

    expect(($this->board)()->accepted)->toBeFalse();

    TransportEligibility::query()->create(['subject_type' => 'employee', 'employee_id' => $this->employee->id, 'status' => 'active', 'effective_from' => '2026-01-01', 'provider_id' => $this->provider->id]);
    expect(($this->board)()->transaction->eligibility_snapshot['basis'])->toBe('employee_grant');
});

it('suspends the route assignment when a transfer moves the employee out of the route, identity unchanged', function (): void {
    Notification::fake();
    $pass = $this->s->pass($this->employee, $this->provider, $this->route);
    $user = User::factory()->create(['employee_id' => $this->employee->id]);
    $employeeId = $this->employee->id;
    $cardUuid = $this->card->public_card_uuid;

    // Transfer to Subcity B: a new current assignment, as CompleteTransferAction writes it.
    $old = EmployeeAssignment::query()->find($this->employee->current_assignment_id);
    $old->update(['is_current' => false, 'assignment_status' => 'closed', 'effective_to' => today()->toDateString()]);
    $new = EmployeeAssignment::query()->create(['employee_id' => $employeeId, 'organization_id' => $this->s->org['subcity_b']->id, 'assignment_status' => 'active', 'effective_from' => today()->toDateString(), 'is_current' => true]);
    $this->employee->forceFill(['current_assignment_id' => $new->id])->save();

    $pass->refresh();
    expect($pass->status)->toBe('suspended')
        ->and($pass->suspended_reason)->toBe('route_no_longer_serves_organization')
        ->and($this->employee->fresh()->id)->toBe($employeeId)
        ->and($this->card->fresh()->public_card_uuid)->toBe($cardUuid)
        ->and(TransportException::query()->where('type', 'eligibility_withdrawn')->count())->toBe(1);
    Notification::assertSentTo($user, TransportNotification::class, fn (TransportNotification $n) => $n->kind === 'assignment_suspended');

    // The next scan is refused on the route, and the historical evidence is untouched.
    expect(($this->board)()->resultCode)->toBe('route_not_allowed_for_employee');
});

it('refuses a separated employee at once and suspends their assignments', function (): void {
    $pass = $this->s->pass($this->employee, $this->provider, $this->route);
    expect(($this->board)()->accepted)->toBeTrue();

    $this->employee->forceFill(['status' => EmployeeStatus::Terminated])->save();

    expect($pass->fresh()->status)->toBe('suspended')
        ->and($pass->fresh()->suspended_reason)->toBe('employee_no_longer_active');
    $evening = $this->s->trip($this->route, ['direction' => 'outbound']);
    expect(($this->board)($evening->id)->resultCode)->toBe('employee_inactive')
        ->and(TransportTransaction::query()->where('status', 'accepted')->count())->toBe(1);
});

it('keeps a revoked card from boarding while its past boardings remain', function (): void {
    $this->s->pass($this->employee, $this->provider, $this->route);
    expect(($this->board)()->accepted)->toBeTrue();

    $this->card->update(['status' => 'revoked', 'revoked_at' => now(), 'is_current' => false]);
    $evening = $this->s->trip($this->route, ['direction' => 'outbound']);

    expect(($this->board)($evening->id)->resultCode)->toBe('id_card_revoked')
        ->and(TransportTransaction::query()->where('status', 'accepted')->count())->toBe(1)
        ->and(TransportTransaction::query()->where('status', 'rejected')->count())->toBe(1);
});
