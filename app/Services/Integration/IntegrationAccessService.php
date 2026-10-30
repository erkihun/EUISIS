<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Enums\ApiScope;
use App\Models\Employee;
use App\Models\ExternalApplication;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Modules\Entitlements\Domain\Models\ServiceProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * One place that answers "may this caller see this organization's data?" for
 * the integration API (F1, F2), so controllers stay thin and no endpoint
 * re-implements token, scope or organization logic.
 *
 * Two principals reach the API:
 *   - ExternalApplication — a registered machine client. Its scope list is the
 *     WHAT; its organization_access (city / scoped / unreviewed) and grant rows
 *     are the WHERE.
 *   - User — a person (session or personal token). The person's own
 *     permission is the WHAT and OrganizationScopeService the WHERE, exactly as
 *     on the web screens, so an API call never shows a person more than the
 *     screens would.
 *
 * The organization of an employee is always resolved server-side from the
 * current assignment; nothing the caller sends can place an employee
 * elsewhere.
 */
class IntegrationAccessService
{
    /** A UUID no row carries, so an empty bound still yields a valid, empty query. */
    private const NO_ORGANIZATION = '00000000-0000-0000-0000-000000000000';

    /** Human permission needed for each integration scope when the caller is a person. */
    public const HUMAN_PERMISSION = [
        'employees.basic_read' => 'employees.view',
        'employee_assignments.read' => 'employees.view',
        'employees.photo_read' => 'employees.view',
        'organizations.read' => 'organizations.view',
        'organization_structure.read' => 'organizations.view',
        'organization_units.read' => 'organization-units.view',
        'positions.read' => 'positions.view',
    ];

    public function __construct(private readonly OrganizationScopeService $scope) {}

    public function principal(Request $request): ExternalApplication|User|null
    {
        $user = $request->user();

        return $user instanceof ExternalApplication || $user instanceof User ? $user : null;
    }

    /** The human actor for audit and domain actions; null for a machine client. */
    public function humanActor(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    /** Text identifying the caller in audit reasons, never a credential. */
    public function callerLabel(Request $request): string
    {
        return $this->labelFor($this->principal($request));
    }

    public function labelFor(ExternalApplication|User|null $principal): string
    {
        return match (true) {
            $principal instanceof ExternalApplication => 'application:'.$principal->code,
            $principal instanceof User => 'user:'.$principal->getKey(),
            default => 'anonymous',
        };
    }

    /**
     * A person must also hold the screen permission behind the scope. A
     * machine client is governed by its scope list alone (EnsureApiScope).
     */
    public function assertHumanPermission(Request $request, string $integrationScope): void
    {
        $principal = $this->principal($request);
        if (! $principal instanceof User) {
            return;
        }
        $permission = self::HUMAN_PERMISSION[$integrationScope] ?? null;
        if ($permission !== null && ! $principal->can($permission)) {
            throw new HttpException(403, 'missing_permission');
        }
    }

    /** The service provider a machine client is bound to (F16); null for a person. */
    public function boundProvider(Request $request): ?ServiceProvider
    {
        $principal = $this->principal($request);

        return $principal instanceof ExternalApplication ? $principal->serviceProvider : null;
    }

    /** Whether the caller may read ID photos: scope for an application, permission for a person. */
    public function mayReadPhotos(Request $request): bool
    {
        $principal = $this->principal($request);

        if ($principal instanceof ExternalApplication) {
            $token = $principal->currentAccessToken();

            return $token !== null && $token->can(ApiScope::EmployeesPhotoRead->value) && in_array(ApiScope::EmployeesPhotoRead->value, $principal->grantableScopes(), true);
        }

        return $principal instanceof User && $principal->can(self::HUMAN_PERMISSION['employees.photo_read']);
    }

    /**
     * Organizations the caller may read, or null for no bound.
     *
     * @return list<string>|null
     */
    public function organizationIds(Request $request): ?array
    {
        return $this->organizationIdsFor($this->principal($request));
    }

    /** @return list<string>|null */
    public function organizationIdsFor(ExternalApplication|User|null $principal): ?array
    {
        if ($principal instanceof ExternalApplication) {
            return match ($principal->organization_access) {
                ExternalApplication::ACCESS_CITY => null,
                ExternalApplication::ACCESS_SCOPED => $this->scope->expandGrants($principal->organizationScopes()->get(['organization_id', 'scope_type']))->map(fn ($id): string => (string) $id)->values()->all(),
                // Not yet reviewed: nothing organization-bound until an administrator decides.
                default => [],
            };
        }

        if ($principal instanceof User) {
            return $this->scope->isUnrestricted($principal) ? null : array_map('strval', $this->scope->allowedOrganizationIds($principal));
        }

        return [];
    }

    /**
     * Constrains a query to the caller's organizations through `$column`
     * (an organization id column on the queried table).
     */
    public function scopeQuery(Builder $query, Request $request, string $column = 'organization_id'): Builder
    {
        $ids = $this->organizationIds($request);
        if ($ids === null) {
            return $query;
        }

        return $query->whereIn($column, $ids === [] ? [self::NO_ORGANIZATION] : $ids);
    }

    /** Employees whose CURRENT assignment lies inside the caller's organizations. */
    public function scopeEmployees(Builder $query, Request $request): Builder
    {
        $ids = $this->organizationIds($request);
        if ($ids === null) {
            return $query;
        }

        return $query->whereHas('currentAssignment', fn ($assignment) => $assignment->whereIn('organization_id', $ids === [] ? [self::NO_ORGANIZATION] : $ids));
    }

    public function canAccessOrganization(Request $request, ?string $organizationId): bool
    {
        return $this->principalCanAccessOrganization($this->principal($request), $organizationId);
    }

    public function principalCanAccessOrganization(ExternalApplication|User|null $principal, ?string $organizationId): bool
    {
        $ids = $this->organizationIdsFor($principal);
        if ($ids === null) {
            return true;
        }

        return $organizationId !== null && in_array($organizationId, $ids, true);
    }

    /**
     * 404, not 403, outside the bounds: a caller must not learn that a record
     * it may not see exists (no cross-scope enumeration).
     */
    public function assertOrganization(Request $request, ?string $organizationId): void
    {
        if (! $this->canAccessOrganization($request, $organizationId)) {
            throw new HttpException(404, 'not_found');
        }
    }

    /** The employee's current organization, resolved from the canonical assignment. */
    public function assertEmployee(Request $request, Employee $employee): void
    {
        $this->assertOrganization($request, $this->employeeOrganizationId($employee));
    }

    public function employeeOrganizationId(Employee $employee): ?string
    {
        $organizationId = $employee->relationLoaded('currentAssignment')
            ? $employee->currentAssignment?->organization_id
            : $employee->currentAssignment()->value('organization_id');

        return $organizationId === null ? null : (string) $organizationId;
    }
}
