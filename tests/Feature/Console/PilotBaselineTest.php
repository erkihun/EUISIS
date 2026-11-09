<?php

declare(strict_types=1);

use App\Console\Commands\PilotBaseline;
use App\Enums\AssignmentStatus;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Grievance\Domain\Models\Grievance;
use Modules\Grievance\Domain\Models\GrievanceCategory;

function pilotOrganization(string $code): Organization
{
    $type = OrganizationType::query()->firstOrCreate(['code' => 'PILOT-T'], ['name_en' => 'Pilot type']);

    return Organization::query()->create(['organization_type_id' => $type->id, 'code' => $code, 'name_en' => 'Pilot '.$code, 'status' => 'active']);
}

function pilotEmployee(Organization $organization, string $number, string $createdAt): Employee
{
    $employee = Employee::query()->create([
        'employee_number' => $number, 'first_name' => 'Pilot', 'last_name' => $number, 'full_name' => 'Pilot '.$number,
        'status' => EmployeeStatus::Active,
    ]);
    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id, 'organization_id' => $organization->id, 'is_current' => true,
        'assignment_status' => AssignmentStatus::Active->value, 'effective_from' => '2026-01-01',
    ]);
    $employee->forceFill(['current_assignment_id' => $assignment->id])->save();
    DB::table('employees')->where('id', $employee->id)->update(['created_at' => $createdAt]);

    return $employee->fresh();
}

function pilotGrievance(Organization $organization, string $reference, ?string $submittedAt, ?string $resolvedAt): void
{
    $category = GrievanceCategory::query()->firstOrCreate(['code' => 'PILOT-CAT'], ['name_en' => 'Working conditions', 'name_am' => 'የሥራ ሁኔታ', 'is_active' => true]);
    $grievance = Grievance::query()->create([
        'reference_number' => $reference, 'organization_id' => $organization->id, 'origin_level' => 'organization',
        'category_id' => $category->id, 'subject' => 'Pilot '.$reference, 'description' => 'Baseline fixture.',
    ]);
    DB::table('grievances')->where('id', $grievance->id)->update(['submitted_at' => $submittedAt, 'resolved_at' => $resolvedAt]);
}

function pilotFeedback(Organization $organization, Employee $employee, string $submittedAt, ?int $rating): void
{
    // ADR 0032: feedback rows live in the Service feedback module's table.
    DB::table('public_service_feedback')->insert([
        'id' => (string) Str::uuid(), 'employee_id' => $employee->id, 'organization_id' => $organization->id,
        'target_type' => 'employee', 'source_type' => 'employee_card_qr', 'type' => 'feedback', 'comment' => 'Baseline',
        'rating' => $rating, 'status' => 'pending', 'submitted_at' => $submittedAt, 'created_at' => $submittedAt, 'updated_at' => $submittedAt,
    ]);
}

it('measures the indicators inside the window only', function (): void {
    $organization = pilotOrganization('PILOT-A');
    $inside = pilotEmployee($organization, 'P-001', '2026-10-10 09:00:00');
    $second = pilotEmployee($organization, 'P-002', '2026-10-20 09:00:00');
    pilotEmployee($organization, 'P-003', '2026-09-01 09:00:00'); // before the window

    pilotGrievance($organization, 'GRV-P1', '2026-10-01 08:00:00', '2026-10-05 08:00:00'); // 4.0 days
    pilotGrievance($organization, 'GRV-P2', '2026-10-02 08:00:00', '2026-10-12 08:00:00'); // 10.0 days
    pilotGrievance($organization, 'GRV-P3', '2026-10-03 08:00:00', '2026-10-04 20:00:00'); // 1.5 days
    pilotGrievance($organization, 'GRV-P4', '2026-08-01 08:00:00', null); // open, over 30 days
    pilotGrievance($organization, 'GRV-P5', '2026-10-25 08:00:00', null); // open, recent

    pilotFeedback($organization, $inside, '2026-10-15 10:00:00', 4);
    pilotFeedback($organization, $inside, '2026-10-16 10:00:00', 2);
    pilotFeedback($organization, $inside, '2026-11-15 10:00:00', 5); // after the window

    IdCard::query()->create(['employee_id' => $inside->id, 'card_number' => 'CARD-P1', 'status' => CardStatus::Issued, 'issued_at' => '2026-10-18 09:00:00']);
    IdCard::query()->create(['employee_id' => $second->id, 'card_number' => 'CARD-P2', 'status' => CardStatus::Issued, 'issued_at' => '2026-12-01 09:00:00']);

    DB::table('audit_logs')->insert([
        ['id' => (string) Str::uuid(), 'event_type' => 'mfa.challenge_failed', 'organization_id' => $organization->id, 'created_at' => '2026-10-11 11:00:00'],
        ['id' => (string) Str::uuid(), 'event_type' => 'mfa.challenge_failed', 'organization_id' => $organization->id, 'created_at' => '2026-09-11 11:00:00'],
    ]);

    $this->artisan('pilot:baseline', ['--from' => '2026-10-01', '--to' => '2026-10-31', '--json' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('"employees_registered": 2');

    $snapshot = app(PilotBaseline::class)->snapshot(
        CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'), null,
    );

    expect($snapshot['indicators'])->toBe([
        'employees_registered' => 2,
        'grievances_submitted' => 4,
        'grievances_resolved' => 3,
        'grievance_median_resolution_days' => 4.0,
        'grievances_open_over_30_days' => 1,
        'public_feedback_submitted' => 2,
        'public_feedback_average_rating' => 3.0,
        'id_cards_issued' => 1,
        'mfa_challenge_failures' => 1,
    ]);
});

it('limits the indicators to one organization and saves the snapshot', function (): void {
    Storage::fake('local');
    $a = pilotOrganization('PILOT-A');
    $b = pilotOrganization('PILOT-B');
    pilotEmployee($a, 'P-010', '2026-10-10 09:00:00');
    pilotEmployee($b, 'P-020', '2026-10-10 09:00:00');
    pilotGrievance($b, 'GRV-B1', '2026-10-02 08:00:00', null);

    $this->artisan('pilot:baseline', ['--from' => '2026-10-01', '--to' => '2026-10-31', '--organization' => $b->id, '--save' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('organization '.$b->id);

    Storage::disk('local')->assertExists('pilot/baseline-2026-10-31-'.$b->id.'.json');
    $saved = json_decode((string) Storage::disk('local')->get('pilot/baseline-2026-10-31-'.$b->id.'.json'), true);
    expect($saved['organization_id'])->toBe($b->id)
        ->and($saved['indicators']['employees_registered'])->toBe(1)
        ->and($saved['indicators']['grievances_submitted'])->toBe(1)
        ->and($saved['indicators']['grievance_median_resolution_days'])->toBeNull();
});

it('rejects a malformed or inverted window', function (): void {
    $this->artisan('pilot:baseline', ['--from' => '10/01/2026'])->assertFailed();
    $this->artisan('pilot:baseline', ['--from' => '2026-10-31', '--to' => '2026-10-01'])->assertFailed();
});
