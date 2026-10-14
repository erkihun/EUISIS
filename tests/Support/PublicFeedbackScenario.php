<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\AssignmentStatus;
use App\Enums\CardStatus;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeFeedbackToken;
use App\Models\IdCard;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\PositionService;
use App\Services\ServiceFeedback\EmployeeFeedbackTokenService;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * One scanned-employee scenario for the public feedback tests: an active
 * organization with a unit, a position that delivers one service, an active
 * employee holding that position and an active ID card, and the employee's
 * active feedback token.
 */
final class PublicFeedbackScenario
{
    public Organization $organization;

    public OrganizationUnit $unit;

    public Position $position;

    public PositionService $positionService;

    public Employee $employee;

    public EmployeeAssignment $assignment;

    public EmployeeFeedbackToken $token;

    public IdCard $card;

    private function __construct() {}

    public static function employeeTarget(string $prefix = 'PFS'): self
    {
        $scenario = new self;

        $type = OrganizationType::query()->firstOrCreate(['code' => $prefix.'-TYPE'], ['name_en' => 'Feedback Type']);

        $scenario->organization = Organization::query()->create([
            'organization_type_id' => $type->id,
            'code' => $prefix.'-ORG',
            'name_en' => 'Feedback Organization',
            'name_am' => 'የአስተያየት ተቋም',
            'status' => 'active',
        ]);

        $scenario->unit = OrganizationUnit::query()->create([
            'organization_id' => $scenario->organization->id,
            'code' => $prefix.'-U1',
            'name_en' => 'Licensing Unit',
            'unit_type' => 'department',
            'status' => 'active',
        ]);

        $scenario->position = Position::query()->create([
            'organization_id' => $scenario->organization->id,
            'organization_unit_id' => $scenario->unit->id,
            'job_position_code' => $prefix.'-P1',
            'title_en' => 'Licensing Officer',
            'title_am' => 'የፈቃድ ባለሙያ',
            'is_active' => true,
        ]);

        $scenario->employee = Employee::query()->create([
            'employee_number' => $prefix.'-EMP-1',
            'first_name' => 'Hanna',
            'last_name' => 'Girma',
            'full_name' => 'Hanna Girma',
            'status' => EmployeeStatus::Active->value,
        ]);

        $scenario->assignment = EmployeeAssignment::query()->create([
            'employee_id' => $scenario->employee->id,
            'organization_id' => $scenario->organization->id,
            'organization_unit_id' => $scenario->unit->id,
            'position_id' => $scenario->position->id,
            'is_current' => true,
            'assignment_status' => AssignmentStatus::Active->value,
            'effective_from' => now()->toDateString(),
        ]);

        $scenario->employee->forceFill(['current_assignment_id' => $scenario->assignment->id])->save();

        $scenario->positionService = PositionService::query()->create([
            'organization_id' => $scenario->organization->id,
            'position_id' => $scenario->position->id,
            'service_no' => $prefix.'-001',
            'name_en' => 'Business Licensing',
            'name_am' => 'የንግድ ፈቃድ',
            'is_active' => true,
            'is_performance_evaluation_enabled' => true,
            'sort_order' => 0,
        ]);

        // No employee service without an active ID card: the scanned employee holds one.
        $scenario->card = self::activeCard($scenario->employee, $prefix.'-CARD-1');

        $scenario->token = app(EmployeeFeedbackTokenService::class)->ensureActiveToken($scenario->employee);

        RateLimiter::clear('sf-submit:'.$scenario->token->token.'|127.0.0.1');
        RateLimiter::clear('sf-submit-ip:127.0.0.1');

        return $scenario;
    }

    /** An ACTIVE, current, unexpired ID card — what the public feedback QR requires of an employee. */
    public static function activeCard(Employee $employee, ?string $cardNumber = null): IdCard
    {
        return IdCard::query()->create([
            'employee_id' => $employee->id,
            'card_number' => $cardNumber ?? 'PFS-CARD-'.Str::random(8),
            'status' => CardStatus::Active,
            'activated_at' => now(),
            'expires_at' => now()->addYear(),
            'is_current' => true,
            'qr_status' => 'active',
            'public_card_uuid' => (string) Str::uuid(),
        ]);
    }
}
