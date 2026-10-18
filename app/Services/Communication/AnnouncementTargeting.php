<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Enums\Communication\AnnouncementTargetType;
use App\Enums\Communication\ConversationType;
use App\Models\Communication\Conversation;
use App\Models\Communication\ConversationMember;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationUnit;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Who an author may address, and which organization the announcement belongs
 * to (its organization drives who may edit, publish and report on it).
 *
 * - City-wide and role targets reach across organizations: they need the
 *   permission without organization restriction.
 * - Organization, subtree and unit targets: the organization must be in the
 *   author's scope.
 * - Employee targets: the employee's current organization must be in scope.
 * - Group targets: only groups the author belongs to.
 *
 * The error never says which target failed.
 */
final class AnnouncementTargeting
{
    public const MAX_TARGETS = 50;

    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly CommunicationHierarchy $hierarchy,
    ) {}

    /**
     * @param  list<array{type: string, id?: ?string}>  $targets
     * @return array{targets: list<array{type: string, id: ?string}>, organization_id: ?string, target_type: string}
     */
    public function resolve(User $author, array $targets, string $permission = 'announcements.create'): array
    {
        $clean = collect($targets)
            ->map(fn (array $t) => ['type' => (string) ($t['type'] ?? ''), 'id' => isset($t['id']) && $t['id'] !== '' ? (string) $t['id'] : null])
            ->unique(fn ($t) => $t['type'].'|'.$t['id'])->values();
        if ($clean->isEmpty()) {
            throw ValidationException::withMessages(['targets' => __('communication.errors.announcement_targets_required')]);
        }
        if ($clean->count() > self::MAX_TARGETS) {
            throw ValidationException::withMessages(['targets' => __('communication.errors.announcement_target_invalid')]);
        }

        $orgs = [];
        $ok = $clean->every(function (array $t) use ($author, $permission, &$orgs): bool {
            $type = AnnouncementTargetType::tryFrom($t['type']);
            if ($type === null || ($type !== AnnouncementTargetType::City && $t['id'] === null)) {
                return false;
            }

            return match ($type) {
                AnnouncementTargetType::City => $t['id'] === null && $this->scope->canExercisePermission($author, $permission, null),
                AnnouncementTargetType::Role => Role::query()->whereKey((int) $t['id'])->exists() && $this->scope->canExercisePermission($author, $permission, null),
                AnnouncementTargetType::Organization, AnnouncementTargetType::OrganizationSubtree => Organization::query()->whereKey($t['id'])->exists()
                    && $this->scope->canExercisePermission($author, $permission, $orgs[] = $t['id']),
                AnnouncementTargetType::OrganizationUnit => ($org = OrganizationUnit::query()->whereKey($t['id'])->value('organization_id')) !== null
                    && $this->scope->canExercisePermission($author, $permission, $orgs[] = (string) $org),
                AnnouncementTargetType::Employee => ($org = $this->employeeOrganization($t['id'])) !== null
                    && $this->scope->canExercisePermission($author, $permission, $orgs[] = $org),
                AnnouncementTargetType::Group => Conversation::query()->whereKey($t['id'])->where('type', ConversationType::Group->value)->whereNull('archived_at')->exists()
                    && ConversationMember::query()->where('conversation_id', $t['id'])->where('user_id', $author->getKey())->whereNull('left_at')->exists()
                    && $this->scope->canExercisePermission($author, $permission, $orgs[] = $this->hierarchy->assignmentOf($author)?->organization_id),
            };
        });
        if (! $ok) {
            throw ValidationException::withMessages(['targets' => __('communication.errors.announcement_target_invalid')]);
        }

        $orgs = array_values(array_unique(array_filter($orgs)));
        $types = $clean->pluck('type')->unique();

        return [
            'targets' => $clean->all(),
            // The first scoped organization owns it (every target was checked against the author's scope);
            // city-wide and role announcements belong to no organization.
            'organization_id' => $orgs !== [] && ! $types->contains(AnnouncementTargetType::City->value) && ! $types->contains(AnnouncementTargetType::Role->value) ? $orgs[0] : null,
            'target_type' => $types->count() === 1 ? (string) $types->first() : 'MULTIPLE',
        ];
    }

    private function employeeOrganization(string $employeeId): ?string
    {
        $org = Employee::query()->whereKey($employeeId)
            ->join('employee_assignments', 'employee_assignments.id', '=', 'employees.current_assignment_id')
            ->value('employee_assignments.organization_id');

        return $org === null ? null : (string) $org;
    }
}
