<?php

declare(strict_types=1);

use App\Enums\AssignmentStatus;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\PositionService;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Feedback\Application\Services\EmployeeFeedbackTokenService;
use Modules\Feedback\Domain\Models\EmployeeServiceFeedback;

/*
 * The anonymous feedback form speaks the visitor's language: every refusal a
 * client can read comes from lang/{en,am}/service-feedback.php, never from a
 * string typed into a request class.
 */

beforeEach(function (): void {
    $type = OrganizationType::query()->create(['code' => 'SFL-TYPE', 'name_en' => 'Feedback Type']);

    $this->org = Organization::query()->create([
        'organization_type_id' => $type->id,
        'code' => 'SFL-ORG',
        'name_en' => 'Feedback Organization',
        'status' => 'active',
    ]);

    $this->unit = OrganizationUnit::query()->create([
        'organization_id' => $this->org->id,
        'code' => 'SFL-U1',
        'name_en' => 'Licensing Unit',
        'unit_type' => 'department',
        'status' => 'active',
    ]);

    $this->position = Position::query()->create([
        'organization_id' => $this->org->id,
        'organization_unit_id' => $this->unit->id,
        'job_position_code' => 'SFL-P1',
        'title_en' => 'Licensing Officer',
        'is_active' => true,
    ]);

    $employee = Employee::query()->create([
        'employee_number' => 'SFL-EMP-1',
        'first_name' => 'Hanna',
        'last_name' => 'Girma',
        'full_name' => 'Hanna Girma',
        'status' => EmployeeStatus::Active->value,
    ]);

    $assignment = EmployeeAssignment::query()->create([
        'employee_id' => $employee->id,
        'organization_id' => $this->org->id,
        'organization_unit_id' => $this->unit->id,
        'position_id' => $this->position->id,
        'is_current' => true,
        'assignment_status' => AssignmentStatus::Active->value,
        'effective_from' => now()->toDateString(),
    ]);

    $employee->forceFill(['current_assignment_id' => $assignment->id])->save();

    $this->token = app(EmployeeFeedbackTokenService::class)->ensureActiveToken($employee);

    RateLimiter::clear('sf-submit:'.$this->token->token.'|127.0.0.1');
    RateLimiter::clear('sf-submit-ip:127.0.0.1');
});

function sflPositionService(string $organizationId, string $positionId, string $no): PositionService
{
    return PositionService::query()->create([
        'organization_id' => $organizationId,
        'position_id' => $positionId,
        'service_no' => $no,
        'name_en' => 'Service '.$no,
        'is_active' => true,
    ]);
}

it('explains a missing service and rating in the visitor language', function (): void {
    $this->withHeader('X-Locale', 'am')
        ->post('/service-feedback/'.$this->token->token, [])
        ->assertSessionHasErrors([
            'position_service_id' => __('service-feedback.validation.service_required', [], 'am'),
            'rating' => __('service-feedback.validation.rating_required', [], 'am'),
        ]);

    expect(__('service-feedback.validation.rating_required', [], 'am'))
        ->not->toContain('service-feedback.')
        ->not->toBe(__('service-feedback.validation.rating_required', [], 'en'));
});

it('keeps the English wording for an English visitor', function (): void {
    $this->post('/service-feedback/'.$this->token->token, pfComplete('feedback', ['rating' => 9]))
        ->assertSessionHasErrors([
            'position_service_id' => 'Please choose the service you received.',
            'rating' => 'Please choose a rating between 1 and 5 stars.',
        ]);
});

it('explains a service another officer provides in the visitor language', function (): void {
    $otherPosition = Position::query()->create([
        'organization_id' => $this->org->id,
        'organization_unit_id' => $this->unit->id,
        'job_position_code' => 'SFL-P2',
        'title_en' => 'Other Officer',
        'is_active' => true,
    ]);
    $foreign = sflPositionService($this->org->id, $otherPosition->id, 'SFL-OTHER');

    $expected = __('service-feedback.service_not_provided', [], 'am');

    $this->withHeader('X-Locale', 'am')
        ->post('/service-feedback/'.$this->token->token, pfComplete('feedback', ['position_service_id' => $foreign->id, 'rating' => 4]))
        ->assertSessionHasErrors(['position_service_id' => $expected]);

    expect($expected)->not->toContain('service-feedback.')
        ->and(EmployeeServiceFeedback::query()->count())->toBe(0);
});
