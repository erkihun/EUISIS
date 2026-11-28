<?php

declare(strict_types=1);

namespace Modules\Reporting\Infrastructure\Integration;

use App\Backbone\Contracts\AccessScopeReader;
use App\Backbone\Data\UserSummaryData;
use Modules\Reporting\Application\Data\ActorContext;
use Modules\Reporting\Application\Data\UserSummary;
use Modules\Reporting\Application\Ports\ReportingAccessResolver;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Modules\Reporting\Infrastructure\Persistence\Models\MonitoringUnit;

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

    public function staffOf(string $unitId, int $limit = 50): array
    {
        return array_map(fn (UserSummaryData $user): array => [
            'user' => self::summary($user),
            'permissions' => $this->reader->grantedAmong($user->userId, ReportingRoles::ALL),
        ], $this->reader->usersPlacedIn($unitId, $limit));
    }

    public function receiversFor(string $permission, string $organizationId, int $limit = 20): array
    {
        $unitId = MonitoringUnit::query()->where('organization_id', $organizationId)->value('organization_unit_id');
        if ($unitId !== null) {
            $staff = array_values(array_filter(
                $this->reader->usersPlacedIn((string) $unitId, 200),
                fn (UserSummaryData $user): bool => $user->active && $this->reader->grantedAmong($user->userId, [$permission]) !== [],
            ));
            if ($staff !== []) {
                return array_map(self::summary(...), array_slice($staff, 0, $limit));
            }
        }

        return $this->holdersFor($permission, $organizationId, $limit);
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

        // Staff of an institution's report follow-up unit reach that institution in this module, whatever their scope rows say.
        $units = $this->reader->unitUuidsOf($userId);
        $monitored = $units === [] ? [] : MonitoringUnit::query()->whereIn('organization_unit_id', $units)->pluck('organization_id')->map(strval(...))->all();

        return new ActorContext(
            $userId, $user->name, $scope->unrestricted,
            array_values(array_unique([...$scope->organizationUuids, ...$monitored])),
            $this->reader->grantedAmong($userId, ReportingRoles::ALL),
            $monitored,
        );
    }

    private static function summary(UserSummaryData $user): UserSummary
    {
        return new UserSummary($user->userId, $user->name, $user->email, $user->organizationUuid);
    }
}
