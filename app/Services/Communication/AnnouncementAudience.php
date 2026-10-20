<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Enums\Communication\AnnouncementStatus;
use App\Enums\Communication\AnnouncementTargetType;
use App\Enums\Communication\ConversationType;
use App\Models\Communication\Announcement;
use App\Models\Communication\AnnouncementTarget;
use App\Models\Communication\ConversationMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Matches a reader to announcement targets without fanning out rows.
 *
 * A reader's audience keys are computed once (their organization and every
 * organization above it, their unit and every unit above it, their employee
 * record, their roles, their groups) and matched against announcement_targets
 * through its (target_type, target_id) index. A city-wide announcement is one
 * target row however many people read it.
 */
final class AnnouncementAudience
{
    /** @var array<int, array<string, list<string>>> */
    private array $keys = [];

    public function __construct(private readonly CommunicationHierarchy $hierarchy) {}

    /** @return array<string, list<string>> target type => ids that reach this reader */
    public function keysFor(User $user): array
    {
        if (isset($this->keys[$user->getKey()])) {
            return $this->keys[$user->getKey()];
        }

        $assignment = $this->hierarchy->assignmentOf($user);
        $orgs = $assignment?->organization_id ? $this->hierarchy->organizationAncestors((string) $assignment->organization_id) : [];
        $units = $assignment?->organization_unit_id ? $this->hierarchy->unitAncestors((string) $assignment->organization_unit_id) : [];

        return $this->keys[$user->getKey()] = [
            AnnouncementTargetType::Organization->value => $assignment?->organization_id ? [(string) $assignment->organization_id] : [],
            // A subtree target reaches everyone in that organization or below it.
            AnnouncementTargetType::OrganizationSubtree->value => $orgs,
            // A unit target reaches the unit and its sub-units.
            AnnouncementTargetType::OrganizationUnit->value => $units,
            AnnouncementTargetType::Employee->value => $user->employee_id ? [(string) $user->employee_id] : [],
            AnnouncementTargetType::Role->value => $user->roles()->pluck('id')->map(fn ($id) => (string) $id)->all(),
            AnnouncementTargetType::Group->value => ConversationMember::query()
                ->join('conversations', 'conversations.id', '=', 'conversation_members.conversation_id')
                ->where('conversation_members.user_id', $user->getKey())->whereNull('conversation_members.left_at')
                ->where('conversations.type', ConversationType::Group->value)
                ->pluck('conversation_members.conversation_id')->map(fn ($id) => (string) $id)->all(),
        ];
    }

    /** Announcements whose targets reach this reader (any status; callers add the status filter). */
    public function scopeReaching(Builder $query, User $user): Builder
    {
        $keys = $this->keysFor($user);

        return $query->whereIn('announcements.id', AnnouncementTarget::query()->select('announcement_id')
            ->where(function (Builder $q) use ($keys): void {
                $q->where('target_type', AnnouncementTargetType::City->value);
                foreach ($keys as $type => $ids) {
                    if ($ids !== []) {
                        $q->orWhere(fn (Builder $t) => $t->where('target_type', $type)->whereIn('target_id', $ids));
                    }
                }
            }));
    }

    /** Live announcements for this reader: published, not expired, addressed to them. */
    public function liveFor(User $user): Builder
    {
        return $this->scopeReaching(Announcement::query(), $user)
            ->where('status', AnnouncementStatus::Published->value)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function reaches(User $user, Announcement $announcement): bool
    {
        return $this->scopeReaching(Announcement::query()->whereKey($announcement->getKey()), $user)->exists();
    }

    /**
     * Everyone an announcement reaches: active users who may read
     * announcements, matched from its targets (the reverse of keysFor). Used
     * for the audience count, notifications and the acknowledgement report.
     *
     * @param  iterable<array{type: string, id: ?string}>|null  $targets  defaults to the announcement's stored targets
     * @return Builder<User>
     */
    public function recipients(?Announcement $announcement, ?iterable $targets = null): Builder
    {
        $rows = collect($targets ?? $announcement?->targets()->get(['target_type', 'target_id'])->map(fn ($t) => ['type' => $t->target_type instanceof AnnouncementTargetType ? $t->target_type->value : (string) $t->target_type, 'id' => $t->target_id]) ?? []);
        $by = fn (AnnouncementTargetType $type) => $rows->where('type', $type->value)->pluck('id')->filter()->map(fn ($id) => (string) $id)->values()->all();

        $query = User::query()->permission('announcements.view')->where('users.status', 'active');
        if ($rows->contains('type', AnnouncementTargetType::City->value)) {
            return $query;
        }

        $orgs = $by(AnnouncementTargetType::Organization);
        foreach ($by(AnnouncementTargetType::OrganizationSubtree) as $root) {
            array_push($orgs, ...$this->hierarchy->organizationDescendants($root));
        }
        $units = [];
        foreach ($by(AnnouncementTargetType::OrganizationUnit) as $root) {
            array_push($units, ...$this->hierarchy->unitDescendants($root));
        }
        $employees = $by(AnnouncementTargetType::Employee);
        $roles = $by(AnnouncementTargetType::Role);
        $groups = $by(AnnouncementTargetType::Group);
        if ($orgs === [] && $units === [] && $employees === [] && $roles === [] && $groups === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($orgs, $units, $employees, $roles, $groups): void {
            if ($orgs !== [] || $units !== []) {
                $q->orWhereIn('users.employee_id', \App\Models\Employee::query()->select('employees.id')
                    ->join('employee_assignments', 'employee_assignments.id', '=', 'employees.current_assignment_id')
                    // Only the lists that have ids: an empty placeholder is not a valid uuid on PostgreSQL.
                    ->where(function ($p) use ($orgs, $units): void {
                        if ($orgs !== []) {
                            $p->orWhereIn('employee_assignments.organization_id', array_values(array_unique($orgs)));
                        }
                        if ($units !== []) {
                            $p->orWhereIn('employee_assignments.organization_unit_id', array_values(array_unique($units)));
                        }
                    }));
            }
            if ($employees !== []) {
                $q->orWhereIn('users.employee_id', $employees);
            }
            if ($roles !== []) {
                $q->orWhereIn('users.id', \Illuminate\Support\Facades\DB::table(config('permission.table_names.model_has_roles', 'model_has_roles'))
                    ->select('model_id')->where('model_type', User::class)->whereIn('role_id', array_map('intval', $roles)));
            }
            if ($groups !== []) {
                $q->orWhereIn('users.id', ConversationMember::query()->select('user_id')->whereIn('conversation_id', $groups)->whereNull('left_at'));
            }
        });
    }

    public function forget(): void
    {
        $this->keys = [];
    }
}
