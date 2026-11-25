<?php

declare(strict_types=1);

namespace App\Backbone\Contracts;

use App\Backbone\Data\AccessScopeData;
use App\Backbone\Data\UserSummaryData;

/**
 * Who a user is and what they may reach, answered by user id so that a
 * module needs neither the User model nor OrganizationScopeService. Scope and
 * permissions come from the one canonical source (roles, scope records).
 */
interface AccessScopeReader
{
    /** The organizations the user may reach; `unrestricted` instead of a list for city-wide accounts. */
    public function scopeOf(int $userId): AccessScopeData;

    /**
     * The permissions, among those named, that the user holds.
     *
     * @param  list<string>  $permissions
     * @return list<string>
     */
    public function grantedAmong(int $userId, array $permissions): array;

    public function user(int $userId): ?UserSummaryData;

    /**
     * @param  list<int>  $userIds
     * @return array<int, UserSummaryData> keyed by user id; unknown ids are absent
     */
    public function users(array $userIds): array;

    /**
     * Active users who hold the permission and may exercise it for the organization, by name.
     *
     * @return list<UserSummaryData>
     */
    public function holdersFor(string $permission, string $organizationUuid, int $limit = 50): array;

    /**
     * Active users whose name or e-mail contains the term; when a list of
     * organizations is given, only users whose home organization is in it.
     *
     * @param  list<string>|null  $withinOrganizationUuids
     * @return list<UserSummaryData>
     */
    /**
     * The work unit the user's linked employee is placed in now, then its
     * parent units, nearest first. Empty without an employee or a current placement.
     *
     * @return list<string>
     */
    public function unitUuidsOf(int $userId): array;

    /**
     * Active users whose linked employee is currently placed in the unit or in a unit beneath it, by name.
     *
     * @return list<UserSummaryData>
     */
    public function usersPlacedIn(string $unitUuid, int $limit = 50): array;

    public function searchUsers(string $term, ?array $withinOrganizationUuids = null, int $limit = 20): array;
}
