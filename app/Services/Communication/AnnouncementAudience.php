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

    public function forget(): void
    {
        $this->keys = [];
    }
}
