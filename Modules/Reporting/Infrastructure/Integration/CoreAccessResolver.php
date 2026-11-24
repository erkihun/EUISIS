<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Integration;

use App\Backbone\Contracts\AccessScopeReader;
use App\Backbone\Data\UserSummaryData;
use Modules\Reporting\Application\Data\ActorContext;
use Modules\Reporting\Application\Data\UserSummary;
use Modules\Reporting\Application\Ports\ReportingAccessResolver;
use Modules\Reporting\Contracts\Data\ReportingRoles;

/** Identity, permissions and organization scope through the Core's AccessScopeReader contract. */
final class CoreAccessResolver implements ReportingAccessResolver
{
    /** @var array<int, ActorContext> one answer per user for the life of the request or job */
    private array $contexts = [];

    public function __construct(private readonly AccessScopeReader $reader) {}

    public function contextFor(int $userId): ActorContext
    {
        return $this->contexts[$userId] ??= $this->resolve($userId);
    }

    public function users(array $userIds): array
    {
        return array_map(self::summary(...), $this->reader->users($userIds));
    }

    public function holdersFor(string $permission, string $organizationId, int $limit = 20): array
    {
        return array_map(self::summary(...), $this->reader->holdersFor($permission, $organizationId, $limit));
    }

    public function searchUsers(ActorContext $actor, string $term, int $limit = 20): array
    {
        return array_map(self::summary(...), $this->reader->searchUsers($term, $actor->organizationFilter(), $limit));
    }

    private function resolve(int $userId): ActorContext
    {
        $user = $this->reader->user($userId);
        if ($user === null || ! $user->active) {
            return new ActorContext($userId, '', false, [], []);
        }
        $scope = $this->reader->scopeOf($userId);

        return new ActorContext($userId, $user->name, $scope->unrestricted, $scope->organizationUuids, $this->reader->grantedAmong($userId, ReportingRoles::ALL));
    }

    private static function summary(UserSummaryData $user): UserSummary
    {
        return new UserSummary($user->userId, $user->name, $user->email, $user->organizationUuid);
    }
}
