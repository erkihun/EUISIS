<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Services;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Communication\Domain\Enums\ChannelType;
use Modules\Communication\Domain\Enums\MembershipSource;
use Modules\Communication\Domain\Models\Channel;
use Modules\Communication\Domain\Models\ConversationMember;

/**
 * Automatic channel membership from the organizational assignment.
 *
 * A channel with auto_membership holds every active user who may use
 * Communication and whose current assignment is in the channel's scope (its
 * organization or unit, with descendants when the channel says so; no scope
 * means city-wide). Rules:
 *
 * - New matches join as AUTO members.
 * - People who left a channel or were removed are not re-added, except in
 *   system-managed channels, which are mandatory.
 * - AUTO members who no longer match (transfer, deactivation, lost access)
 *   are removed when the channel says remove_on_transfer. MANUAL members are
 *   never removed by the sync.
 */
final class ChannelMembershipSync
{
    public function __construct(
        private readonly CommunicationHierarchy $hierarchy,
        private readonly MembershipWriter $writer,
        private readonly WriteAuditLogAction $audit,
    ) {}

    /** @return Collection<int, int> */
    public function eligibleUserIds(Channel $channel): Collection
    {
        $query = User::query()->permission('communication.access')
            ->where('users.status', 'active')
            ->join('employees', 'employees.id', '=', 'users.employee_id')
            ->join('employee_assignments', 'employee_assignments.id', '=', 'employees.current_assignment_id');
        $this->scopeTo($query, $channel);

        return $query->distinct()->pluck('users.id')->map(fn ($id) => (int) $id);
    }

    /** @return array{added: int, removed: int} */
    public function syncChannel(Channel $channel, ?User $actor = null): array
    {
        if (! $channel->auto_membership || $channel->archived_at !== null) {
            return ['added' => 0, 'removed' => 0];
        }
        $conversation = $channel->conversation;
        $eligible = $this->eligibleUserIds($channel)->flip();
        $rows = ConversationMember::query()->where('conversation_id', $conversation->getKey())
            ->get(['user_id', 'source', 'left_at', 'left_reason'])->keyBy(fn ($m) => (int) $m->user_id);

        $mandatory = $channel->type === ChannelType::SystemManaged;
        $toAdd = $eligible->keys()->filter(function (int $id) use ($rows, $mandatory): bool {
            $row = $rows->get($id);

            return $row === null || ($row->left_at !== null && ($mandatory || $row->left_reason === 'SYNC'));
        })->values();

        $toRemove = $channel->remove_on_transfer
            ? $rows->filter(fn ($m, int $id) => $m->left_at === null && $m->source === MembershipSource::Auto && ! $eligible->has($id))->keys()
            : collect();

        $added = $this->writer->add($conversation, $toAdd, MembershipSource::Auto, null);
        $removed = $this->writer->remove($conversation, $toRemove, 'SYNC');

        if ($added !== [] || $removed !== []) {
            $this->writer->announce($conversation, $added, $removed, perPersonLimit: 25);
            $this->audit->execute(AuditEventType::CommunicationMembershipsSynced, $actor, $channel, $channel->organization_id,
                null, ['added' => count($added), 'removed' => count($removed)]);
        }

        return ['added' => count($added), 'removed' => count($removed)];
    }

    /**
     * One person's assignment changed: bring every automatic channel up to
     * date for them only.
     *
     * @return array{added: int, removed: int}
     */
    public function syncUser(User $user): array
    {
        $assignment = $user->status === 'active' && $user->can('communication.access') ? $this->hierarchy->assignmentOf($user) : null;
        $userId = (int) $user->getKey();
        $rows = ConversationMember::query()->where('user_id', $userId)->get(['conversation_id', 'source', 'left_at', 'left_reason'])->keyBy('conversation_id');
        $added = 0;
        $removed = 0;

        Channel::query()->where('auto_membership', true)->whereNull('archived_at')->with('conversation')->orderBy('id')
            ->chunk(200, function (Collection $channels) use ($assignment, $rows, $userId, &$added, &$removed): void {
                foreach ($channels as $channel) {
                    $row = $rows->get($channel->conversation_id);
                    $belongs = $assignment !== null && $this->covers($channel, (string) $assignment->organization_id, (string) $assignment->organization_unit_id);
                    $mandatory = $channel->type === ChannelType::SystemManaged;

                    if ($belongs && ($row === null || ($row->left_at !== null && ($mandatory || $row->left_reason === 'SYNC')))) {
                        if ($this->writer->add($channel->conversation, [$userId], MembershipSource::Auto, null) !== []) {
                            $this->writer->announce($channel->conversation, [$userId], []);
                            $added++;
                        }
                    } elseif (! $belongs && $row !== null && $row->left_at === null && $row->source === MembershipSource::Auto && $channel->remove_on_transfer) {
                        $this->writer->remove($channel->conversation, [$userId], 'SYNC');
                        $this->writer->announce($channel->conversation, [], [$userId]);
                        $removed++;
                    }
                }
            });

        if ($added + $removed > 0) {
            $this->audit->execute(AuditEventType::CommunicationMembershipsSynced, null, $user, $assignment?->organization_id,
                null, ['added' => $added, 'removed' => $removed]);
        }

        return ['added' => $added, 'removed' => $removed];
    }

    /** True when a person placed in this organization and unit falls in the channel's scope. */
    public function covers(Channel $channel, string $organizationId, string $unitId): bool
    {
        if ($channel->organization_unit_id !== null) {
            $units = $channel->include_descendants ? $this->hierarchy->unitDescendants((string) $channel->organization_unit_id) : [(string) $channel->organization_unit_id];

            return in_array($unitId, $units, true);
        }
        if ($channel->organization_id === null) {
            return true;
        }
        $orgs = $channel->include_descendants ? $this->hierarchy->organizationDescendants((string) $channel->organization_id) : [(string) $channel->organization_id];

        return in_array($organizationId, $orgs, true);
    }

    private function scopeTo(Builder $query, Channel $channel): void
    {
        if ($channel->organization_unit_id !== null) {
            $query->whereIn('employee_assignments.organization_unit_id', $channel->include_descendants
                ? $this->hierarchy->unitDescendants((string) $channel->organization_unit_id)
                : [(string) $channel->organization_unit_id]);
        } elseif ($channel->organization_id !== null) {
            $query->whereIn('employee_assignments.organization_id', $channel->include_descendants
                ? $this->hierarchy->organizationDescendants((string) $channel->organization_id)
                : [(string) $channel->organization_id]);
        }
    }
}
