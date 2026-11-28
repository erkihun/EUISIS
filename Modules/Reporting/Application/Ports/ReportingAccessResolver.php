<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Ports;

use Modules\Reporting\Application\Data\ActorContext;
use Modules\Reporting\Application\Data\UserSummary;

/** Identity and authorization facts, from whoever owns users, roles and organization scope. */
interface ReportingAccessResolver
{
    /** The security context of a signed-in user. An unknown or inactive user gets one that reaches nothing. */
    public function contextFor(int $userId): ActorContext;

    /**
     * @param  list<int>  $userIds
     * @return array<int, UserSummary> keyed by user id
     */
    public function users(array $userIds): array;

    /**
     * Active users who hold the permission for the organization.
     *
     * @return list<UserSummary>
     */
    /**
     * Who is told about, and acts on, the reports an institution receives:
     * the staff of its report follow-up unit who hold the permission, when the
     * institution has designated one and someone there holds it; otherwise
     * everyone who holds the permission for the institution.
     *
     * @return list<UserSummary>
     */
    public function receiversFor(string $permission, string $organizationId, int $limit = 20): array;

    /**
     * The people who staff a work unit (placed in it or in a unit beneath
     * it), by name, each with the Reporting permissions they hold.
     *
     * @return list<array{user: UserSummary, permissions: list<string>}>
     */
    public function staffOf(string $unitId, int $limit = 50): array;

    public function holdersFor(string $permission, string $organizationId, int $limit = 20): array;

    /**
     * People the actor may name as responsible, reviewer or owner; bounded and scoped.
     *
     * @return list<UserSummary>
     */
    public function searchUsers(ActorContext $actor, string $term, int $limit = 20): array;
}
