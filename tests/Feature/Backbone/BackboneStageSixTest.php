<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\IdCard;
use App\Models\Integration\IntegrationEvent;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Services\IdCards\CardQrPayloadService;
use App\Services\Integration\Events\EventCatalog;
use App\Services\Positions\PositionCapacityService;
use Illuminate\Support\Carbon;

/*
 * Stage 6 of the master backbone separation: explicit occupancy and
 * card-state integration events. They are additive: the generic events stay,
 * the employee is never named on a position event, and each type can be
 * switched off by configuration.
 */

beforeEach(function (): void {
    $type = OrganizationType::query()->create(['code' => 'S6-T', 'name_en' => 'Stage six type']);
    $this->organization = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'S6-ORG', 'name_en' => 'Stage six', 'status' => 'active']);
    $this->position = Position::query()->create(['organization_id' => $this->organization->id, 'job_position_code' => 'S6-P', 'title_en' => 'Officer', 'is_active' => true]);
    $this->employee = Employee::query()->create(['employee_number' => 'S6-EMP', 'first_name' => 'Stage', 'last_name' => 'Six', 'full_name' => 'Stage Six', 'status' => EmployeeStatus::Active->value]);
    $this->assignment = EmployeeAssignment::query()->create([
        'employee_id' => $this->employee->id, 'organization_id' => $this->organization->id, 'position_id' => $this->position->id,
        'is_current' => true, 'assignment_status' => AssignmentStatus::Active->value, 'effective_from' => '2026-01-01',
    ]);
    $this->employee->forceFill(['current_assignment_id' => $this->assignment->id])->save();
    $this->establishment = PositionEstablishment::query()->create([
        'establishment_number' => 'S6-EST', 'organization_id' => $this->organization->id, 'position_id' => $this->position->id,
        'approved_slots' => 1, 'effective_from' => '2026-01-01', 'status' => 'approved',
    ]);
});

/** @return list<IntegrationEvent> */
function stageSixEvents(string $type): array
{
    return IntegrationEvent::query()->where('event_type', $type)->orderBy('id')->get()->all();
}

it('records a seat taken and released without naming the employee', function (): void {
    $capacity = app(PositionCapacityService::class);
    $occupancy = $capacity->recordOccupancy($this->establishment, $this->assignment, Carbon::parse('2026-01-01'));

    [$occupied] = stageSixEvents('position.occupied');
    expect($occupied->aggregate_type)->toBe('position')
        ->and($occupied->aggregate_uuid)->toBe($this->position->id)
        ->and($occupied->organization_id)->toBe($this->organization->id)
        ->and($occupied->payload)->toBe([
            'position_uuid' => $this->position->id, 'organization_uuid' => $this->organization->id, 'occupancy_uuid' => $occupancy->id,
            'effective_date' => '2026-01-01', 'state' => 'occupied',
        ])
        ->and(json_encode($occupied->payload))->not->toContain($this->employee->id)
        ->and(EventCatalog::validate('position.occupied', $occupied->payload))->toBe([]);

    $capacity->releaseOccupancy($occupancy->fresh(), 'transferred', Carbon::parse('2026-06-30'));
    [$vacated] = stageSixEvents('position.vacated');
    expect($vacated->payload['state'])->toBe('vacated')
        ->and($vacated->payload['effective_date'])->toBe('2026-06-30')
        ->and($vacated->sequence)->toBeGreaterThan($occupied->sequence)
        ->and(EventCatalog::isTombstone('position.vacated', $vacated->payload))->toBeFalse();

    // Releasing an already released seat changes nothing and records nothing.
    $capacity->releaseOccupancy($occupancy->fresh(), 'transferred', Carbon::parse('2026-06-30'));
    expect(stageSixEvents('position.vacated'))->toHaveCount(1);
});

it('records explicit card states beside the generic status event', function (): void {
    $card = IdCard::query()->create(['employee_id' => $this->employee->id, 'card_number' => 'S6-C1', 'status' => CardStatus::Issued->value, 'is_current' => true, 'expires_at' => '2028-01-31']);
    app(CardQrPayloadService::class)->ensurePublicReference($card);
    $card->refresh();

    $card->update(['status' => CardStatus::Active->value]);
    $card->update(['status' => CardStatus::Suspended->value]);
    $card->update(['status' => CardStatus::Expired->value]);

    [$activated] = stageSixEvents('id_card.activated');
    expect($activated->payload)->toMatchArray(['card_uuid' => $card->public_card_uuid, 'status' => 'active', 'previous_status' => 'issued', 'expires_at' => '2028-01-31'])
        ->and($activated->change_kind)->toBe('upsert')
        ->and(stageSixEvents('id_card.suspended')[0]->change_kind)->toBe('tombstone')
        ->and(stageSixEvents('id_card.expired')[0]->payload['previous_status'])->toBe('suspended')
        // The generic event is still there for every change.
        ->and(collect(stageSixEvents('id_card.status_changed'))->pluck('payload.status')->all())->toBe(['active', 'suspended', 'expired']);
});

it('records nothing for a type an operator switched off', function (): void {
    config(['integration.events.disabled_types' => ['position.occupied', 'id_card.suspended']]);
    app(PositionCapacityService::class)->recordOccupancy($this->establishment, $this->assignment, Carbon::parse('2026-01-01'));
    $card = IdCard::query()->create(['employee_id' => $this->employee->id, 'card_number' => 'S6-C2', 'status' => CardStatus::Active->value, 'is_current' => true]);
    app(CardQrPayloadService::class)->ensurePublicReference($card);
    $card->refresh()->update(['status' => CardStatus::Suspended->value]);

    expect(stageSixEvents('position.occupied'))->toBe([])
        ->and(stageSixEvents('id_card.suspended'))->toBe([])
        ->and(stageSixEvents('id_card.status_changed'))->toHaveCount(1);
});

it('offers the new types to partners holding the matching scope only', function (): void {
    expect(EventCatalog::typesForScopes(['positions.read']))->toContain('position.occupied', 'position.vacated')
        ->and(EventCatalog::typesForScopes(['positions.read']))->not->toContain('id_card.activated')
        ->and(EventCatalog::typesForScopes(['id_cards.verify']))->toContain('id_card.activated', 'id_card.suspended', 'id_card.expired');
});
