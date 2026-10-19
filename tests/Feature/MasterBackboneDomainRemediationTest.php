<?php

declare(strict_types=1);

use App\Actions\Positions\ArchivePositionAction;
use App\Actions\Transfers\CompleteTransferAction;
use App\Enums\AssignmentStatus;
use App\Enums\EmployeeStatus;
use App\Enums\EstablishmentStatus;
use App\Enums\OrganizationDesignStatus;
use App\Enums\OrganizationStatus;
use App\Enums\TransferAnnouncementStatus;
use App\Enums\TransferApplicationStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\JobDescription;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\Position;
use App\Models\PositionEstablishment;
use App\Models\PositionProfile;
use App\Models\TransferAnnouncement;
use App\Models\TransferApplication;
use App\Models\TransferSetting;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

/**
 * Master backbone remediation — domain guards.
 *
 *   - a position holding a live assignment cannot be archived under its occupant;
 *   - a transfer's new assignment carries the destination job description in force.
 */
beforeEach(function (): void {
    foreach (['positions.archive', 'positions.view', 'positions.viewAny', 'transfers.complete'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $type = OrganizationType::query()->firstOrCreate(['code' => 'MBD'], ['name_en' => 'Remediation']);
    $this->from = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'MBD-FROM', 'name_en' => 'From', 'status' => OrganizationStatus::Active]);
    $this->to = Organization::query()->create(['organization_type_id' => $type->id, 'code' => 'MBD-TO', 'name_en' => 'To', 'status' => OrganizationStatus::Active]);
    $this->fromPosition = mbdPosition($this->from, 'MBD-P1');
    $this->toPosition = mbdPosition($this->to, 'MBD-P2');

    $this->employee = Employee::query()->create(['employee_number' => 'MBD-EMP', 'first_name' => 'Almaz', 'last_name' => 'Tesfaye', 'full_name' => 'Almaz Tesfaye', 'gender' => 'F', 'status' => EmployeeStatus::Active]);
    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $this->employee->id, 'organization_id' => $this->from->id, 'position_id' => $this->fromPosition->id,
        'assignment_status' => AssignmentStatus::Active, 'effective_from' => now()->subYear()->toDateString(), 'is_current' => true,
    ]);
    $this->employee->update(['current_assignment_id' => $assignment->id]);
    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo(['positions.archive', 'positions.view', 'transfers.complete']);
});

function mbdPosition(Organization $organization, string $code): Position
{
    $position = Position::query()->create(['organization_id' => $organization->id, 'title_en' => 'Position '.$code, 'job_position_code' => $code, 'is_active' => true]);
    PositionEstablishment::query()->create([
        'organization_id' => $organization->id, 'position_id' => $position->id, 'establishment_number' => 'EST-'.$code,
        'approved_slots' => 3, 'status' => EstablishmentStatus::Approved, 'effective_from' => now()->subYears(2)->toDateString(),
    ]);

    return $position;
}

function mbdPublishedJobDescription(Position $position, string $from, ?string $to = null, int $version = 1, ?string $lineage = null): JobDescription
{
    $profile = PositionProfile::query()->create([
        'position_id' => $position->id, 'organization_id' => $position->organization_id, 'lineage_key' => (string) Str::uuid(),
        'version_no' => $version, 'status' => OrganizationDesignStatus::Published, 'effective_from' => $from,
    ]);

    return JobDescription::query()->create([
        'position_id' => $position->id, 'organization_id' => $position->organization_id, 'position_profile_id' => $profile->id,
        'lineage_key' => $lineage ?? (string) Str::uuid(), 'version_no' => $version,
        'status' => $to === null ? OrganizationDesignStatus::Published : OrganizationDesignStatus::Superseded,
        'effective_from' => $from, 'effective_to' => $to,
    ]);
}

// ── Position archive guard ───────────────────────────────────────────────

it('refuses to archive a position that still holds an active assignment', function (): void {
    expect(fn () => app(ArchivePositionAction::class)->execute($this->fromPosition, $this->actor, 'restructure'))
        ->toThrow(ValidationException::class);

    expect(Position::query()->whereKey($this->fromPosition->id)->exists())->toBeTrue()
        ->and($this->fromPosition->fresh()->is_active)->toBeTrue();
});

it('shows the archive refusal as a validation message on the web route, not a 500', function (): void {
    $this->actingAs($this->actor)
        ->from(route('positions.show', $this->fromPosition))
        ->delete(route('positions.archive', $this->fromPosition))
        ->assertSessionHasErrors('position');

    expect(Position::query()->whereKey($this->fromPosition->id)->exists())->toBeTrue();
});

it('archives a vacant position', function (): void {
    app(ArchivePositionAction::class)->execute($this->toPosition, $this->actor, 'no longer needed');

    expect(Position::query()->whereKey($this->toPosition->id)->exists())->toBeFalse()
        ->and(Position::withTrashed()->whereKey($this->toPosition->id)->exists())->toBeTrue();
});

// ── Transfer destination job description ─────────────────────────────────

it('gives the transferred assignment the destination job description in force', function (): void {
    $lineage = (string) Str::uuid();
    mbdPublishedJobDescription($this->toPosition, now()->subYears(2)->toDateString(), now()->subYear()->subDay()->toDateString(), 1, $lineage);
    $current = mbdPublishedJobDescription($this->toPosition, now()->subYear()->toDateString(), null, 2, $lineage);
    $sourceJd = mbdPublishedJobDescription($this->fromPosition, now()->subYear()->toDateString());
    EmployeeAssignment::query()->whereKey($this->employee->current_assignment_id)->update(['job_description_id' => $sourceJd->id]);

    $announcement = TransferAnnouncement::query()->create([
        'organization_id' => $this->to->id, 'position_id' => $this->toPosition->id, 'number_of_vacancies' => 1,
        'opening_date' => now()->subDay()->toDateString(), 'closing_date' => now()->addMonth()->toDateString(),
        'status' => TransferAnnouncementStatus::Published, 'created_by' => $this->actor->id,
    ]);
    $application = TransferApplication::query()->create([
        'announcement_id' => $announcement->id, 'employee_id' => $this->employee->id, 'current_assignment_id' => $this->employee->current_assignment_id,
        'releasing_organization_id' => $this->from->id, 'receiving_organization_id' => $this->to->id,
        'status' => TransferApplicationStatus::Approved, 'submitted_at' => now(),
    ]);
    TransferSetting::current()->update([
        'releasing_consent_required' => false, 'receiving_consent_required' => false, 'final_approval_required' => false,
        'card_reprint_policy' => 'no_reprint', 'service_recalculation_policy' => 'no_recalculation',
    ]);

    $oldAssignmentId = $this->employee->current_assignment_id;
    app(CompleteTransferAction::class)->execute($application->fresh(), $this->actor);

    $new = EmployeeAssignment::query()->findOrFail($this->employee->fresh()->current_assignment_id);
    expect($new->position_id)->toBe($this->toPosition->id)
        ->and($new->job_description_id)->toBe($current->id)
        // History keeps the job description the employee held before.
        ->and(EmployeeAssignment::query()->find($oldAssignmentId)->job_description_id)->toBe($sourceJd->id)
        // Identity never moves with a transfer.
        ->and($this->employee->fresh()->employee_number)->toBe('MBD-EMP');
});
