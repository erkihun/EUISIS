<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Enums\Communication\ChannelType;
use App\Enums\Communication\ConversationType;
use App\Models\Communication\CommunicationBlock;
use App\Models\Communication\Conversation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Who may be added to a group or channel by a given person.
 *
 * Everyone added must be an active user of Communication. Groups and private
 * or project channels add people within the adder's directory reach who have
 * not blocked them (either way). Organization and system-managed channels add
 * people placed in the channel's scope. The error never says which person
 * failed or why, so it cannot be used to probe blocks or the directory.
 */
final class MemberEligibility
{
    public function __construct(
        private readonly CommunicationDirectory $directory,
        private readonly ChannelMembershipSync $sync,
        private readonly CommunicationHierarchy $hierarchy,
    ) {}

    public static function maxGroupMembers(): int
    {
        return max(2, (int) config('communication.max_group_members', 256));
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, User>
     */
    public function resolve(User $actor, array $userIds, ?Conversation $conversation = null, string $field = 'user_ids'): Collection
    {
        $ids = collect($userIds)->map(fn ($id) => (int) $id)->unique()->reject(fn (int $id) => $id === (int) $actor->getKey())->values();
        $users = User::query()->whereIn('id', $ids)->where('status', 'active')->get();
        $channel = $conversation?->type === ConversationType::Channel ? $conversation->channel : null;
        $placed = $channel !== null && in_array($channel->type, [ChannelType::PublicOrganization, ChannelType::SystemManaged], true);

        $ok = $users->count() === $ids->count() && $users->every(function (User $user) use ($actor, $placed, $channel): bool {
            if (! $user->can('communication.access')) {
                return false;
            }
            if ($placed) {
                $assignment = $this->hierarchy->assignmentOf($user);

                return $assignment !== null && $this->sync->covers($channel, (string) $assignment->organization_id, (string) $assignment->organization_unit_id);
            }

            return $this->directory->canReach($actor, $user) && ! CommunicationBlock::between((int) $actor->getKey(), (int) $user->getKey());
        });

        if (! $ok) {
            throw ValidationException::withMessages([$field => __('communication.errors.members_unavailable')]);
        }

        return $users;
    }

    /** Names for a system note: up to three, then "+N". */
    public static function names(Collection $users): string
    {
        $names = $users->pluck('name')->take(3)->implode(', ');

        return $users->count() > 3 ? $names.' +'.($users->count() - 3) : $names;
    }
}
