<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\CoreAuditLog;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\IdCard;
use App\Models\OutboxEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsGoldenPath;
use Tests\TestCase;

/** P1 (ADR 0008): draft → submitted → verified → active, with separation of duties, before any card. */
class CoreEmployeeLifecycleTest extends TestCase
{
    use BuildsGoldenPath, RefreshDatabase;

    private const ALL_CHECKS = ['identity_confirmed' => true, 'official_name_checked' => true, 'organization_confirmed' => true,
        'position_confirmed' => true, 'assignment_confirmed' => true];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildGoldenPath();
    }

    private function person(string $role): User
    {
        return User::query()->create([
            'name' => $role.' '.Str::random(4), 'email' => Str::random(8).'@core.test', 'password' => 'secret-password-1',
            'role' => $role, 'scope_type' => User::SCOPE_CITYWIDE, 'is_active' => true,
        ]);
    }

    private function as(User $user): void
    {
        Auth::forgetGuards();
        Sanctum::actingAs($user);
    }

    private function register(User $officer): string
    {
        $this->as($officer);

        return $this->postJson('/api/v1/employees', ['first_name' => 'Sara', 'last_name' => 'Alemu', 'position_id' => $this->positionB->id, 'effective_from' => '2025-01-01'])
            ->assertCreated()->assertJsonPath('data.status', Employee::DRAFT)->json('data.id');
    }

    public function test_the_golden_path_runs_from_registration_through_approval_to_a_verified_card(): void
    {
        [$clerk, $verifier, $approver, $cardApprover] = [$this->person('core_officer'), $this->person('core_officer'), $this->person('core_admin'), $this->person('core_admin')];
        $id = $this->register($clerk);

        // A draft holds no seat, gets no card and is not served to other systems.
        $this->assertSame(EmployeeAssignment::PENDING, EmployeeAssignment::query()->where('employee_id', $id)->value('assignment_status'));
        $this->assertSame(0, $this->positionB->holders()->count());
        $this->getJson("/api/v1/employees/{$id}/card-eligibility")->assertJsonStructure(['problems' => ['EMPLOYEE_NOT_APPROVED']]);
        $this->postJson("/api/v1/employees/{$id}/card-requests", ['request_type' => 'new'])->assertUnprocessable();
        $this->assertFalse(OutboxEvent::query()->where('event_type', 'employee.created')->exists());

        $this->postJson("/api/v1/employees/{$id}/submit")->assertOk()->assertJsonPath('data.status', Employee::SUBMITTED);
        $this->as($verifier);
        $this->postJson("/api/v1/employees/{$id}/verify", ['checks' => ['identity_confirmed' => true]])->assertUnprocessable()->assertJsonValidationErrors('checks');
        $this->postJson("/api/v1/employees/{$id}/verify", ['checks' => self::ALL_CHECKS, 'notes' => 'ID and appointment letter seen'])
            ->assertOk()->assertJsonPath('data.status', Employee::VERIFIED);
        $this->as($approver);
        $this->postJson("/api/v1/employees/{$id}/approve")->assertOk()->assertJsonPath('data.status', Employee::ACTIVE)->assertJsonPath('data.verification_status', 'approved');

        $this->assertSame(EmployeeAssignment::ACTIVE, EmployeeAssignment::query()->where('employee_id', $id)->value('assignment_status'));
        $this->assertSame(1, $this->positionB->holders()->count());
        $this->assertTrue(OutboxEvent::query()->where('event_type', 'employee.created')->where('entity_id', $id)->exists());
        foreach (['employee.registered', 'employee.submitted', 'employee.verified', 'employee.approved'] as $action) {
            $this->assertTrue(CoreAuditLog::query()->where('action', $action)->where('subject_id', $id)->exists(), $action);
        }

        // Then the card path: request, a second person approves, print, issue, activate, verify.
        $this->giveVerifiedPhoto($id);
        $this->as($clerk);
        $requestId = $this->postJson("/api/v1/employees/{$id}/card-requests", ['request_type' => 'new'])->assertCreated()->json('data.id');
        $this->as($cardApprover);
        $cardId = $this->postJson("/api/v1/card-requests/{$requestId}/approve")->assertOk()->json('data.id');
        $this->postJson("/api/v1/id-cards/{$cardId}/print")->assertOk();
        // The quality check is someone else's (ADR 0010).
        $this->postJson("/api/v1/id-cards/{$cardId}/quality-check", ['result' => 'passed'])->assertUnprocessable();
        $this->as($verifier);
        $this->postJson("/api/v1/id-cards/{$cardId}/quality-check", ['result' => 'passed'])->assertOk()->assertJsonPath('data.qc_status', 'passed');
        $this->as($cardApprover);
        foreach (['issue', 'activate'] as $step) {
            $this->postJson("/api/v1/id-cards/{$cardId}/{$step}")->assertOk();
        }
        $client = new ApiClient(['name' => 'Cafeteria', 'scopes' => ['cards:verify'], 'scope_type' => User::SCOPE_CITYWIDE, 'is_active' => true]);
        $client->save();
        $secret = $client->createToken('integration', ['cards:verify'])->plainTextToken;
        Auth::forgetGuards();
        $this->postJson('/api/v1/integration/cards/verify', ['card_uuid' => IdCard::query()->findOrFail($cardId)->public_card_uuid], ['Authorization' => 'Bearer '.$secret])
            ->assertOk()->assertJsonPath('valid', true);
    }

    public function test_invalid_transitions_and_self_approval_are_refused(): void
    {
        $clerk = $this->person('core_officer');
        $id = $this->register($clerk);

        $this->postJson("/api/v1/employees/{$id}/verify", ['checks' => self::ALL_CHECKS])->assertUnprocessable()->assertJsonPath('errors.employee.0', 'This employee must be submitted before verification.');
        $this->as($this->person('core_admin'));
        $this->postJson("/api/v1/employees/{$id}/approve")->assertUnprocessable()->assertJsonPath('errors.employee.0', 'This employee must be verified before approval.');

        $this->as($clerk);
        $this->postJson("/api/v1/employees/{$id}/submit")->assertOk();
        $this->postJson("/api/v1/employees/{$id}/submit")->assertUnprocessable();
        // Whoever submitted does not verify their own submission.
        $this->postJson("/api/v1/employees/{$id}/verify", ['checks' => self::ALL_CHECKS])->assertUnprocessable();

        $verifier = $this->person('core_officer');
        $this->as($verifier);
        $this->postJson("/api/v1/employees/{$id}/verify", ['checks' => self::ALL_CHECKS])->assertOk();
        $this->postJson("/api/v1/employees/{$id}/verify", ['checks' => self::ALL_CHECKS])->assertUnprocessable()->assertJsonPath('errors.employee.0', 'This employee has already been verified.');
        // An officer may not approve; an administrator who registered, submitted or verified may not either.
        $this->postJson("/api/v1/employees/{$id}/approve")->assertForbidden();
        $verifier->forceFill(['role' => 'core_admin'])->save();
        $this->as($verifier->fresh());
        $this->postJson("/api/v1/employees/{$id}/approve")->assertUnprocessable()->assertJsonPath('errors.employee.0', 'You cannot approve an employee you registered, submitted or verified.');

        // The employment-status form cannot shortcut approval, and what was verified cannot be edited.
        $this->postJson("/api/v1/employees/{$id}/status", ['status' => 'active', 'reason' => 'Skip the approval'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->putJson("/api/v1/employees/{$id}", ['first_name' => 'Changed'])->assertUnprocessable();

        $this->as($this->person('core_admin'));
        $this->postJson("/api/v1/employees/{$id}/approve")->assertOk();
        $this->postJson("/api/v1/employees/{$id}/approve")->assertUnprocessable()->assertJsonPath('errors.employee.0', 'This employee has already been approved.');
        $this->postJson("/api/v1/employees/{$id}/verify", ['checks' => self::ALL_CHECKS])->assertUnprocessable();
    }

    public function test_a_record_is_returned_for_correction_with_a_reason_and_resubmitted(): void
    {
        $clerk = $this->person('core_officer');
        $id = $this->register($clerk);
        $this->postJson("/api/v1/employees/{$id}/submit")->assertOk();

        $this->as($this->person('core_officer'));
        $this->postJson("/api/v1/employees/{$id}/return")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/employees/{$id}/return", ['reason' => 'Middle name differs from the ID'])->assertOk()
            ->assertJsonPath('data.status', Employee::DRAFT)->assertJsonPath('data.verification_status', 'returned');
        $this->assertDatabaseHas('core_audit_logs', ['action' => 'employee.returned', 'subject_id' => $id, 'reason' => 'Middle name differs from the ID']);

        $this->as($clerk);
        $this->putJson("/api/v1/employees/{$id}", ['middle_name' => 'Bekele'])->assertOk();
        $this->postJson("/api/v1/employees/{$id}/submit")->assertOk()->assertJsonPath('data.status', Employee::SUBMITTED);
    }

    public function test_verification_needs_possible_duplicates_reviewed_and_approval_rechecks_the_seat(): void
    {
        $clerk = $this->person('core_officer');
        $id = $this->register($clerk);
        // A twin made outside the Core's registration (e.g. imported): no reviewer decision about the pair.
        $this->placedEmployee('EMP-TWIN', $this->positionA, '2024-01-01')->update(['first_name' => 'Sara', 'last_name' => 'Alemu', 'full_name' => 'Sara Alemu']);
        $this->postJson("/api/v1/employees/{$id}/submit")->assertOk();
        $this->as($this->person('core_officer'));
        $this->postJson("/api/v1/employees/{$id}/verify", ['checks' => self::ALL_CHECKS])->assertUnprocessable()
            ->assertJsonPath('errors.employee.0', 'Possible duplicates must be reviewed first: EMP-TWIN.');

        DB::table('employee_duplicate_reviews')->insert(['id' => (string) Str::uuid7(), 'employee_id' => $id, 'matched_employee_id' => Employee::query()->where('employee_number', 'EMP-TWIN')->value('id'),
            'match_level' => 'possible_match', 'decision' => 'not_duplicate', 'reason' => 'Different parents on file', 'decided_by' => $this->person('core_officer')->id, 'decided_at' => now(),
            'created_at' => now(), 'updated_at' => now()]);
        $this->postJson("/api/v1/employees/{$id}/verify", ['checks' => self::ALL_CHECKS])->assertOk();

        // Meanwhile someone else took the only seat: approval refuses rather than over-filling it.
        $this->placedEmployee('EMP-SEAT', $this->positionB, '2025-02-01');
        $this->as($this->person('core_admin'));
        $this->postJson("/api/v1/employees/{$id}/approve")->assertUnprocessable()->assertJsonPath('errors.employee.0', 'This position is already occupied: it has no free seat.');
        $this->assertSame(Employee::VERIFIED, Employee::query()->findOrFail($id)->status);
    }

    public function test_the_screens_show_the_workflow_with_only_the_allowed_actions(): void
    {
        $clerk = $this->person('core_officer');
        $id = $this->register($clerk);
        Auth::forgetGuards();
        $this->actingAs($clerk, 'web');

        $this->get("/employees/{$id}")->assertOk()->assertInertia(fn ($page) => $page->component('Employees/Show')
            ->where('workflow.status', Employee::DRAFT)->where('workflow.can.submit', true)->where('workflow.can.approve', false)
            ->where('workflow.steps.0.key', 'registered')->where('workflow.steps.0.by', $clerk->name));
        $this->post("/employees/{$id}/workflow/submit")->assertRedirect("/employees/{$id}");
        $this->get("/employees/{$id}")->assertInertia(fn ($page) => $page->where('workflow.status', Employee::SUBMITTED)->where('workflow.can.verify', false)->where('workflow.can.return', true));
        $this->get('/employees?status=submitted')->assertInertia(fn ($page) => $page->where('statusOptions.1', 'submitted')->where('employees.0.id', $id));
    }

    public function test_existing_employees_are_kept_as_legacy_approved(): void
    {
        // As before the migration: an active employee with no workflow state.
        Employee::query()->whereKey($this->employee->id)->update(['verification_status' => null]);
        $migration = require database_path('migrations/2026_10_26_110000_add_employee_verification_workflow.php');
        $migration->backfill();

        $legacy = Employee::query()->findOrFail($this->employee->id);
        $this->assertSame(Employee::ACTIVE, $legacy->status);
        $this->assertSame('legacy_approved', $legacy->verification_status);
        $this->assertTrue($this->card->fresh()->isValidCredential());
    }
}
