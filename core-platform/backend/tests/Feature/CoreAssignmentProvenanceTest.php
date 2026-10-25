<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\RegisterEmployee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeDocument;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

/** P3 (ADR 0010): each placement says how it came about, from where, on what evidence, and what it followed. */
class CoreAssignmentProvenanceTest extends TestCase
{
    use BuildsGoldenPath, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGoldenPath();
    }

    private function document(string $employeeId): EmployeeDocument
    {
        $id = (string) Str::uuid7();
        $document = new EmployeeDocument;
        $document->forceFill([
            'id' => $id, 'employee_id' => $employeeId, 'kind' => EmployeeDocument::DOCUMENT, 'document_type' => 'transfer_letter', 'lineage_id' => $id,
            'version' => 1, 'is_current' => true, 'status' => EmployeeDocument::PENDING, 'storage_disk' => 'evidence', 'storage_path' => "{$employeeId}/document/{$id}.pdf",
            'original_filename' => 'letter.pdf', 'mime_type' => 'application/pdf', 'file_size' => 100, 'checksum' => hash('sha256', $id),
        ])->save();

        return $document;
    }

    public function test_a_transfer_records_its_type_reason_document_source_and_the_placement_it_follows(): void
    {
        $officer = $this->actingAsCore('core_officer');
        $letter = $this->document($this->employee->id);
        $previous = $this->employee->current_assignment_id;

        $this->postJson('/api/v1/employees/'.$this->employee->id.'/transfer', [
            'position_id' => $this->positionB->id, 'effective_date' => '2025-03-01', 'reason' => 'Annual reorganization',
            'assignment_type' => 'promotion', 'reason_code' => 'promotion', 'source_document_id' => $letter->id, 'external_reference' => 'HR-2025-17',
        ])->assertOk();

        $next = EmployeeAssignment::query()->findOrFail($this->employee->fresh()->current_assignment_id);
        $this->assertSame(['promotion', 'promotion', 'api', 'HR-2025-17'], [$next->assignment_type, $next->reason_code, $next->source_system, $next->external_reference]);
        $this->assertSame((string) $letter->id, (string) $next->source_document_id);
        $this->assertSame((string) $previous, (string) $next->previous_assignment_id);
        $this->assertSame($officer->id, (int) $next->created_by);
        $this->assertSame($officer->id, (int) $next->approved_by);
        $this->assertTrue($next->is_primary);
    }

    public function test_a_transfer_refuses_unknown_codes_a_stranger_document_and_a_reused_reference(): void
    {
        $this->actingAsCore('core_officer');
        $stranger = $this->placedEmployee('EMP-0002', $this->positionB, '2024-01-01');
        $theirs = $this->document($stranger->id);
        $transfer = fn (array $extra) => $this->postJson('/api/v1/employees/'.$this->employee->id.'/transfer', ['position_id' => $this->positionB->id, 'effective_date' => '2025-03-01'] + $extra);
        // positionB already holds EMP-0002; give it room so only the provenance can refuse.
        Position::query()->whereKey($this->positionB->id)->first()->establishments()->create([
            'establishment_number' => 'EST-B', 'organization_id' => $this->bureauB->id, 'approved_slots' => 3, 'effective_from' => '2020-01-01', 'status' => 'approved',
        ]);

        $transfer(['reason_code' => 'because'])->assertJsonValidationErrors('reason_code');
        $transfer(['assignment_type' => 'initial'])->assertJsonValidationErrors('assignment_type');
        $transfer(['source_document_id' => $theirs->id])->assertJsonValidationErrors('source_document_id');
        $transfer(['external_reference' => 'HR-1'])->assertOk();
        $this->postJson('/api/v1/employees/'.$stranger->id.'/transfer', ['position_id' => $this->positionA->id, 'effective_date' => '2025-03-01', 'external_reference' => 'HR-1'])
            ->assertJsonValidationErrors('external_reference');
    }

    public function test_a_registered_placement_is_the_initial_one_and_approval_names_its_approver(): void
    {
        $registrar = User::query()->create(['name' => 'Registrar', 'email' => 'r@core.test', 'password' => 'secret-password', 'role' => 'core_officer', 'scope_type' => User::SCOPE_CITYWIDE, 'is_active' => true]);
        Position::query()->whereKey($this->positionB->id)->first()->establishments()->create([
            'establishment_number' => 'EST-B2', 'organization_id' => $this->bureauB->id, 'approved_slots' => 3, 'effective_from' => '2020-01-01', 'status' => 'approved',
        ]);
        $employee = app(RegisterEmployee::class)->execute(['first_name' => 'Tigist', 'last_name' => 'Alemu', 'position_id' => $this->positionB->id, 'effective_from' => '2025-01-01'], $registrar);
        $this->giveVerifiedPhoto($employee->id);

        $placement = $employee->currentAssignment;
        $this->assertSame(['initial', 'registration', 'core', null], [$placement->assignment_type, $placement->reason_code, $placement->source_system, $placement->approved_by]);
        $this->assertSame($registrar->id, (int) $placement->created_by);

        $this->approveEmployee($employee->id);
        $placement->refresh();
        $this->assertSame(EmployeeAssignment::ACTIVE, $placement->assignment_status);
        $this->assertNotNull($placement->approved_by);
        $this->assertNotSame($registrar->id, (int) $placement->approved_by);
    }
}
