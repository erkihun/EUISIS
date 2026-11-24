<?php

declare(strict_types=1);

namespace App\Backbone\Adapters;

use App\Backbone\Contracts\AccessScopeReader;
use App\Backbone\Data\AccessScopeData;
use App\Backbone\Data\UserSummaryData;
use App\Enums\OrganizationScopeType;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/** Answers from the users table and the one canonical OrganizationScopeService. */
final readonly class EloquentAccessScopeReader implements AccessScopeReader
{
    /**
     * Resolved per call, never held: the scope service keeps a per-request cache, and this
     * adapter is a singleton that also serves long-lived queue workers. Holding the service
     * would keep answering with the scope a user had when the worker started.
     */
    private function scope(): OrganizationScopeService
    {
        return app(OrganizationScopeService::class);
    }

    public function scopeOf(int $userId): AccessScopeData
    {
        $user = User::query()->find($userId);
        if ($user === null || ! $user->isActive()) {
            return new AccessScopeData(false, []);
        }
        // City-wide by role, or by an active city-wide scope record: no list of every organization is built.
        if ($this->scope()->isUnrestricted($user)
            || $user->organizationScopes()->active()->where('scope_type', OrganizationScopeType::Citywide->value)->exists()) {
            return new AccessScopeData(true, []);
        }

        return new AccessScopeData(false, array_values(array_map(strval(...), $this->scope()->allowedOrganizationIds($user))));
    }

    public function grantedAmong(int $userId, array $permissions): array
    {
        $user = User::query()->find($userId);
        if ($user === null || ! $user->isActive()) {
            return [];
        }

        return array_values(array_filter($permissions, fn (string $permission): bool => $user->can($permission)));
    }

    public function user(int $userId): ?UserSummaryData
    {
        return $this->users([$userId])[$userId] ?? null;
    }

    public function users(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map(intval(...), $userIds))));
        if ($userIds === []) {
            return [];
        }

        return User::query()->whereKey($userIds)->get(['id', 'name', 'email', 'default_organization_id', 'status'])
            ->mapWithKeys(fn (User $user): array => [(int) $user->getKey() => self::summary($user)])
            ->all();
    }

    public function holdersFor(string $permission, string $organizationUuid, int $limit = 50): array
    {
        if (! Str::isUuid($organizationUuid)) {
            return [];
        }
        $holders = [];
        // Role and direct grants first (bounded), then the scope rule per candidate.
        User::query()->where('status', 'active')->permission($permission)->orderBy('name')->limit(500)->get()
            ->each(function (User $user) use (&$holders, $permission, $organizationUuid, $limit): bool {
                if ($this->scope()->canExercisePermission($user, $permission, $organizationUuid)) {
                    $holders[] = self::summary($user);
                }

                return count($holders) < $limit;
            });

        return $holders;
    }

    public function searchUsers(string $term, ?array $withinOrganizationUuids = null, int $limit = 20): array
    {
        $like = ci_like_operator();
        $escaped = addcslashes(trim($term), '%_\\');

        return User::query()->where('status', 'active')
            ->when($escaped !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('name', $like, "%{$escaped}%")->orWhere('email', $like, "%{$escaped}%")))
            ->when($withinOrganizationUuids !== null, fn (Builder $q) => $q->whereIn('default_organization_id', $withinOrganizationUuids))
            ->orderBy('name')->limit(max(1, min($limit, 50)))
            ->get(['id', 'name', 'email', 'default_organization_id', 'status'])
            ->map(fn (User $user): UserSummaryData => self::summary($user))->values()->all();
    }

    private static function summary(User $user): UserSummaryData
    {
        return new UserSummaryData(
            userId: (int) $user->getKey(),
            name: (string) $user->name,
            email: $user->email,
            organizationUuid: $user->default_organization_id === null ? null : (string) $user->default_organization_id,
            active: $user->isActive(),
        );
    }
}
