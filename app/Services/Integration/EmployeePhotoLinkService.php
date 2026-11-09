<?php

declare(strict_types=1);

namespace App\Services\Integration;

use App\Models\Employee;
use App\Models\ExternalApplication;
use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Short-lived signed links to an employee's ID photo (F17).
 *
 * The card verification endpoint used to hand partners a permanent public
 * `/storage/...` URL — a photo that anyone holding the link could fetch for
 * ever. A link is now:
 *   - signed, so it cannot be forged or altered;
 *   - temporary (PHOTO_TTL_MINUTES), so it is useful at the terminal and dead afterwards;
 *   - bound to the principal that asked, which the serving endpoint re-checks
 *     (still active, still allowed that organization) before streaming a byte.
 */
class EmployeePhotoLinkService
{
    public const TTL_MINUTES = 5;

    public function __construct(private readonly IntegrationAccessService $access) {}

    public function forPrincipal(Employee $employee, ExternalApplication|User $principal): ?string
    {
        if (blank($employee->photo_path)) {
            return null;
        }

        return URL::temporarySignedRoute('api.v1.employees.photo', now()->addMinutes(self::TTL_MINUTES), [
            'employee' => $employee->getKey(),
            'principal' => $this->encode($principal),
        ]);
    }

    public function encode(ExternalApplication|User $principal): string
    {
        return ($principal instanceof ExternalApplication ? 'application:' : 'user:').$principal->getKey();
    }

    /** Resolves the principal a link was issued to; null when it no longer exists. */
    public function decode(?string $encoded): ExternalApplication|User|null
    {
        if (! is_string($encoded) || ! str_contains($encoded, ':')) {
            return null;
        }

        [$type, $id] = explode(':', $encoded, 2);

        return match ($type) {
            'application' => ExternalApplication::query()->find($id),
            'user' => User::query()->find($id),
            default => null,
        };
    }

    /** Whether the principal may still see this employee's photo right now. */
    public function mayServe(ExternalApplication|User $principal, Employee $employee): bool
    {
        if ($principal instanceof ExternalApplication) {
            if (! $principal->isActive() || ! in_array('employees.photo_read', $principal->grantableScopes(), true)) {
                return false;
            }
        } elseif (! $principal->can(IntegrationAccessService::HUMAN_PERMISSION['employees.photo_read'])) {
            return false;
        }

        return $this->access->principalCanAccessOrganization($principal, $this->access->employeeOrganizationId($employee));
    }
}
