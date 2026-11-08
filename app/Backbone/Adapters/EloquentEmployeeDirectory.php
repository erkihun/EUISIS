<?php

declare(strict_types=1);

namespace App\Backbone\Adapters;

use App\Backbone\Contracts\EmployeeDirectory;
use App\Backbone\Data\EmployeeIdentity;
use App\Enums\AssignmentStatus;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Support\Str;

final class EloquentEmployeeDirectory implements EmployeeDirectory
{
    private const COLUMNS = ['id', 'employee_number', 'full_name', 'name_en', 'status', 'current_assignment_id'];

    public function __construct(private readonly OrganizationScopeService $scope) {}

    public function findByUuid(string $employeeUuid): ?EmployeeIdentity
    {
        // A malformed id is "not found", never a uuid cast error on PostgreSQL.
        if (! Str::isUuid($employeeUuid)) {
            return null;
        }

        $employee = Employee::query()->whereKey($employeeUuid)->first(self::COLUMNS);

        return $employee === null ? null : EmployeeIdentity::fromModel($employee);
    }

    public function findByNumber(string $employeeNumber): ?EmployeeIdentity
    {
        $employee = Employee::query()->where('employee_number', trim($employeeNumber))->first(self::COLUMNS);

        return $employee === null ? null : EmployeeIdentity::fromModel($employee);
    }

    public function findFor(User $viewer, string $employeeUuid): ?EmployeeIdentity
    {
        if (! Str::isUuid($employeeUuid)) {
            return null;
        }

        $employee = Employee::query()->whereKey($employeeUuid)->first(self::COLUMNS);

        // The canonical scope rule: an employee is where their current assignment is.
        return $employee !== null && $this->scope->canAccessEmployee($viewer, $employee) ? EmployeeIdentity::fromModel($employee) : null;
    }

    public function currentlyIn(array $unitUuids, int $limit = 500): array
    {
        $unitUuids = array_values(array_filter($unitUuids, fn ($id): bool => is_string($id) && Str::isUuid($id)));
        if ($unitUuids === []) {
            return [];
        }

        return Employee::query()
            ->where('status', 'active')
            ->whereIn('current_assignment_id', EmployeeAssignment::query()
                ->where('is_current', true)
                ->where('assignment_status', AssignmentStatus::Active->value)
                ->whereIn('organization_unit_id', $unitUuids)
                ->select('id'))
            ->orderBy('full_name')
            ->limit($limit)
            ->get(self::COLUMNS)
            ->map(fn (Employee $employee): EmployeeIdentity => EmployeeIdentity::fromModel($employee))
            ->values()
            ->all();
    }
}
