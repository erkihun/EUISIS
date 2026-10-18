<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Enums\Communication\CommunicationScope;
use App\Models\Communication\CommunicationUserSetting;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who an employee may find and message (docs/communication-module.md §Directory).
 *
 * The reach is a system setting — own organization, own organization and
 * everything under it, the organizations the person is assigned to manage, or
 * the city. Search is server-side, 2+ characters, 20 rows; a full list of
 * employees never reaches the browser. Only directory-safe fields are returned:
 * no national ID, salary, address, documents or HR history.
 */
final class CommunicationDirectory
{
    public const LIMIT = 20;

    public function __construct(
        private readonly CommunicationSettings $settings,
        private readonly CommunicationHierarchy $hierarchy,
        private readonly OrganizationScopeService $scope,
    ) {}

    /** @return list<string>|null organization ids this user may reach; null = the whole city */
    public function reachableOrganizations(User $user): ?array
    {
        $scope = $this->settings->directoryScope();
        if ($scope === CommunicationScope::CityWide) {
            return null;
        }
        $own = $this->hierarchy->assignmentOf($user)?->organization_id;
        $orgs = match ($scope) {
            CommunicationScope::OwnOrganization => $own ? [(string) $own] : [],
            CommunicationScope::OwnSubtree => $own ? $this->hierarchy->organizationDescendants((string) $own) : [],
            CommunicationScope::AssignedOrganizationScopes => array_values(array_unique(array_filter([
                $own ? (string) $own : null,
                ...array_map('strval', $this->scope->assignedOrganizationIds($user)),
            ]))),
        };

        return $orgs;
    }

    public function canReach(User $actor, User $target): bool
    {
        if ($actor->is($target) || $target->status !== 'active' || $target->employee_id === null) {
            return false;
        }
        $orgs = $this->reachableOrganizations($actor);
        if ($orgs === null) {
            return true;
        }
        $targetOrg = $this->hierarchy->assignmentOf($target)?->organization_id;

        return $targetOrg !== null && in_array((string) $targetOrg, $orgs, true);
    }

    /** @return Collection<int, array<string, mixed>> */
    public function search(User $actor, string $term, string $locale): Collection
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return collect();
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

        // Reach first, then the term: the employee record's names and number, or the
        // account name people know a colleague by (it often differs from the record).
        $users = User::query()
            ->where('users.status', 'active')->whereNotNull('users.employee_id')->whereKeyNot($actor->getKey())
            ->whereIn('users.employee_id', $this->employeesInReach($actor)->select('employees.id'))
            ->where(fn (Builder $q) => $q
                ->where('users.name', ci_like_operator(), $like)
                ->orWhereIn('users.employee_id', Employee::query()->where(fn (Builder $e) => $e
                    ->where('full_name', ci_like_operator(), $like)
                    ->orWhere('name_en', ci_like_operator(), $like)
                    ->orWhere('employee_number', ci_like_operator(), $like))->select('employees.id')))
            ->orderBy('users.name')->limit(self::LIMIT)->get(['users.id', 'users.name', 'users.employee_id']);

        return $this->present($actor, $users, $locale);
    }

    /**
     * @param  iterable<User>  $users
     * @return Collection<int, array<string, mixed>>
     */
    public function present(User $actor, iterable $users, string $locale): Collection
    {
        $users = collect($users);
        $employees = Employee::query()->whereIn('id', $users->pluck('employee_id')->filter())
            ->with(['currentAssignment.organization:id,name_en,name_am', 'currentAssignment.organizationUnit:id,name_en,name_am', 'currentAssignment.position:id,title_en,title_am'])
            ->get(['id', 'full_name', 'name_en', 'metadata', 'employee_number', 'photo_path', 'current_assignment_id'])->keyBy('id');
        $settings = CommunicationUserSetting::query()->whereIn('user_id', $users->pluck('id'))->get()->keyBy('user_id');
        $showNumber = $actor->can('employees.view');
        $name = fn ($m) => $m === null ? null : ($locale === 'am' && filled($m->name_am ?? null) ? $m->name_am : $m->name_en);

        return $users->map(function (User $u) use ($employees, $settings, $showNumber, $locale, $name): array {
            $e = $employees->get($u->employee_id);
            /** @var EmployeeAssignment|null $a */
            $a = $e?->currentAssignment;

            return [
                'id' => $u->getKey(),
                'name' => ($e === null ? null : ($locale === 'am' ? $e->fullNameAm() : $e->fullNameEn())) ?? $u->name,
                'employee_number' => $showNumber ? $e?->employee_number : null,
                'organization' => $name($a?->organization),
                'unit' => $name($a?->organizationUnit),
                'position' => $a?->position ? ($locale === 'am' && filled($a->position->title_am) ? $a->position->title_am : $a->position->title_en) : null,
                'has_photo' => filled($e?->photo_path),
                ...$this->presence($settings->get($u->getKey())),
            ];
        })->values();
    }

    /** @return array{online: ?bool, last_seen_at: ?string} — null when hidden by policy or the person */
    public function presence(?CommunicationUserSetting $setting): array
    {
        $online = null;
        $lastSeen = null;
        if ($this->settings->allowPresence() && ($setting?->show_online_status ?? true)) {
            $online = $setting?->last_seen_at !== null && $setting->last_seen_at->gte(now()->subSeconds($this->settings->onlineWindowSeconds()));
        }
        if ($this->settings->allowLastSeen() && ($setting?->show_last_seen ?? true) && $setting?->last_seen_at !== null) {
            $lastSeen = $setting->last_seen_at->toIso8601String();
        }

        return ['online' => $online, 'last_seen_at' => $lastSeen];
    }

    private function employeesInReach(User $actor): Builder
    {
        $query = Employee::query()->where('employees.status', 'active');
        $orgs = $this->reachableOrganizations($actor);
        if ($orgs !== null) {
            $query->whereIn('employees.current_assignment_id', EmployeeAssignment::query()->select('id')
                ->where('assignment_status', 'active')->whereIn('organization_id', $orgs === [] ? ['00000000-0000-0000-0000-000000000000'] : $orgs));
        }

        return $query;
    }
}
