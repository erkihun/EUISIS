<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CoreAuditLog;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\IdCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

/** The Golden Path rules on every change: scope, seat capacity, one current placement, audit. */
class CoreWriteTest extends TestCase
{
    use BuildsGoldenPath;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGoldenPath();
    }

    private function registration(array $overrides = []): array
    {
        return [
            'employee_number' => 'EMP-0100',
            'first_name' => 'Almaz',
            'last_name' => 'Kebede',
            'position_id' => $this->positionB->id,
            'effective_from' => '2025-01-01',
            ...$overrides,
        ];
    }

    public function test_a_viewer_cannot_change_master_data(): void
    {
        $this->actingAsCore('core_viewer');

        $this->postJson('/api/v1/employees', $this->registration())->assertForbidden();
        $this->postJson('/api/v1/id-cards/'.$this->card->id.'/suspend', ['reason' => 'Lost card'])->assertForbidden();
    }

    public function test_an_officer_registers_an_employee_with_one_current_placement(): void
    {
        $this->actingAsCore('core_officer');

        $id = $this->postJson('/api/v1/employees', $this->registration())->assertCreated()->json('data.id');

        $employee = Employee::query()->findOrFail($id);
        $assignment = EmployeeAssignment::query()->where('employee_id', $id)->sole();
        $this->assertSame($assignment->id, $employee->current_assignment_id);
        $this->assertTrue($assignment->is_current);
        $this->assertSame($this->bureauB->id, $assignment->organization_id);
        $this->assertSame('employee.registered', CoreAuditLog::query()->sole()->action);
    }

    public function test_a_full_position_refuses_another_holder(): void
    {
        $this->actingAsCore('core_officer');

        $this->postJson('/api/v1/employees', $this->registration(['position_id' => $this->positionA->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('position_id');
        $this->assertSame(1, Employee::query()->count());
    }

    public function test_a_scoped_officer_cannot_place_outside_the_scope(): void
    {
        $this->actingAsCore('core_officer', $this->bureauA);

        $this->postJson('/api/v1/employees', $this->registration())->assertUnprocessable()->assertJsonValidationErrors('position_id');
    }

    public function test_a_duplicate_employee_number_is_refused(): void
    {
        $this->actingAsCore('core_officer');

        $this->postJson('/api/v1/employees', $this->registration(['employee_number' => 'EMP-0001']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employee_number');
    }

    public function test_a_transfer_closes_the_old_placement_the_day_before(): void
    {
        $this->actingAsCore('core_officer');
        $old = $this->employee->current_assignment_id;

        $this->postJson('/api/v1/employees/'.$this->employee->id.'/transfer', ['position_id' => $this->positionB->id, 'effective_date' => '2025-03-01'])
            ->assertOk()
            ->assertJsonPath('data.position_id', $this->positionB->id);

        $closed = EmployeeAssignment::query()->findOrFail($old);
        $this->assertFalse($closed->is_current);
        $this->assertSame(EmployeeAssignment::CLOSED, $closed->assignment_status);
        $this->assertSame('2025-02-28', $closed->effective_to->toDateString());
        $this->assertSame(1, EmployeeAssignment::query()->where('employee_id', $this->employee->id)->where('is_current', true)->count());
        $this->assertNotSame($old, $this->employee->fresh()->current_assignment_id);
        $this->assertSame('assignment.transferred', CoreAuditLog::query()->sole()->action);
    }

    public function test_a_transfer_cannot_start_before_the_current_placement(): void
    {
        $this->actingAsCore('core_officer');

        $this->postJson('/api/v1/employees/'.$this->employee->id.'/transfer', ['position_id' => $this->positionB->id, 'effective_date' => '2023-12-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('effective_date');
    }

    public function test_a_separated_employee_cannot_be_transferred(): void
    {
        $this->actingAsCore('core_officer');
        $this->employee->forceFill(['status' => Employee::SEPARATED[0]])->save();

        $this->postJson('/api/v1/employees/'.$this->employee->id.'/transfer', ['position_id' => $this->positionB->id, 'effective_date' => '2025-03-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employee');
    }

    public function test_a_card_is_suspended_reinstated_and_revoked(): void
    {
        $this->actingAsCore('core_officer');
        $url = '/api/v1/id-cards/'.$this->card->id;

        $this->postJson($url.'/suspend', ['reason' => 'Reported lost'])->assertOk()->assertJsonPath('data.status', IdCard::SUSPENDED);
        $this->postJson('/api/v1/cards/verify', ['card_uuid' => $this->card->public_card_uuid])->assertJsonPath('valid', false);

        $this->postJson($url.'/reinstate', ['reason' => 'Card was found'])->assertOk()->assertJsonPath('data.status', IdCard::ACTIVE);
        $this->postJson($url.'/revoke', ['reason' => 'Employee asked for revocation'])->assertOk()->assertJsonPath('data.status', IdCard::REVOKED);

        $this->postJson($url.'/reinstate', ['reason' => 'Try to bring it back'])->assertUnprocessable();
        $this->assertSame(['card.suspend', 'card.reinstate', 'card.revoke'], CoreAuditLog::query()->orderBy('created_at')->orderBy('id')->pluck('action')->all());
    }

    public function test_a_card_change_needs_a_reason(): void
    {
        $this->actingAsCore('core_officer');

        $this->postJson('/api/v1/id-cards/'.$this->card->id.'/suspend', ['reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_every_response_carries_a_request_id_that_the_audit_records(): void
    {
        $this->actingAsCore('core_officer');

        $requestId = $this->withHeader('X-Request-ID', 'req-abc-123')
            ->postJson('/api/v1/id-cards/'.$this->card->id.'/suspend', ['reason' => 'Reported lost'])
            ->assertOk()
            ->assertHeader('X-Request-ID', 'req-abc-123')
            ->headers->get('X-Request-ID');

        $this->assertSame($requestId, CoreAuditLog::query()->sole()->request_id);
    }
}
