<?php

declare(strict_types=1);

use App\Enums\PublicFeedbackSourceType;
use App\Enums\PublicFeedbackTargetType;
use App\Enums\ServiceFeedbackStatus;
use App\Enums\ServiceFeedbackType;
use App\Models\EmployeeServiceFeedback;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PublicFeedbackScenario;

/*
 * Every public submission has exactly one primary target — the employee whose
 * QR was scanned, or the organization whose QR was scanned — and the source
 * that produced it. PHP refuses anything else; PostgreSQL refuses it again at
 * the table, so a direct insert cannot bypass the rule (brief §23, 24).
 */

beforeEach(function (): void {
    $this->scenario = PublicFeedbackScenario::employeeTarget('TIT');
});

it('stores an employee submission with the employee target, the card QR source and a submission time', function (): void {
    $this->post('/service-feedback/'.$this->scenario->token->token, [
        'position_service_id' => $this->scenario->positionService->id,
        'rating' => 4,
    ])->assertRedirect();

    $row = EmployeeServiceFeedback::query()->sole();

    expect($row->target_type)->toBe(PublicFeedbackTargetType::Employee)
        ->and($row->source_type)->toBe(PublicFeedbackSourceType::EmployeeCardQr)
        ->and($row->employee_id)->toBe($this->scenario->employee->id)
        ->and($row->organization_target_id)->toBeNull()
        ->and($row->submitted_at)->not->toBeNull()
        ->and($row->target_name_snapshot)->toBe('Licensing Officer')
        ->and($row->target_code_snapshot)->toBe('TIT-EMP-1');
});

it('refuses a submission that names both targets or neither in PHP', function (): void {
    $base = [
        'type' => ServiceFeedbackType::Suggestion,
        'comment' => 'x',
        'status' => ServiceFeedbackStatus::Pending,
        'target_type' => PublicFeedbackTargetType::Employee,
        'source_type' => PublicFeedbackSourceType::EmployeeCardQr,
    ];

    expect(fn () => EmployeeServiceFeedback::query()->create($base + [
        'employee_id' => $this->scenario->employee->id,
        'organization_target_id' => $this->scenario->organization->id,
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => EmployeeServiceFeedback::query()->create($base))
        ->toThrow(InvalidArgumentException::class);

    expect(EmployeeServiceFeedback::query()->count())->toBe(0);
});

it('refuses a source that does not match the target in PHP', function (): void {
    expect(fn () => EmployeeServiceFeedback::query()->create([
        'type' => ServiceFeedbackType::Feedback,
        'rating' => 3,
        'status' => ServiceFeedbackStatus::Pending,
        'target_type' => PublicFeedbackTargetType::Employee,
        'source_type' => PublicFeedbackSourceType::OrganizationFeedbackQr,
        'employee_id' => $this->scenario->employee->id,
    ]))->toThrow(InvalidArgumentException::class);
});

it('enforces target integrity at the database on PostgreSQL', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('CHECK constraints are declared for PostgreSQL only.');
    }

    $row = [
        'id' => (string) Str::uuid(),
        'type' => 'feedback',
        'rating' => 3,
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    // Both targets named.
    expect(fn () => DB::table('employee_service_feedback')->insert($row + [
        'target_type' => 'employee',
        'source_type' => 'employee_card_qr',
        'employee_id' => $this->scenario->employee->id,
        'organization_target_id' => $this->scenario->organization->id,
    ]))->toThrow(QueryException::class);

    // Organization target through the employee card source.
    expect(fn () => DB::table('employee_service_feedback')->insert($row + [
        'id' => (string) Str::uuid(),
        'target_type' => 'organization',
        'source_type' => 'employee_card_qr',
        'organization_target_id' => $this->scenario->organization->id,
    ]))->toThrow(QueryException::class);

    expect(DB::table('employee_service_feedback')->count())->toBe(0);
});

it('keeps the employee target when the employee later transfers', function (): void {
    $this->post('/service-feedback/'.$this->scenario->token->token.'/suggestion', [
        'message' => 'Open earlier on Mondays.',
    ])->assertRedirect();

    $row = EmployeeServiceFeedback::query()->sole();
    $before = $row->only(['employee_id', 'employee_assignment_id', 'organization_id', 'organization_unit_id', 'position_id', 'target_name_snapshot']);

    $this->scenario->assignment->update(['is_current' => false]);
    $this->scenario->employee->forceFill(['current_assignment_id' => null])->save();

    expect($row->fresh()->only(array_keys($before)))->toBe($before);
});
