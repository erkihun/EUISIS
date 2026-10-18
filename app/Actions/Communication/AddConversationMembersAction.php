<?php

declare(strict_types=1);

namespace App\Actions\Communication;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\Communication\ConversationType;
use App\Enums\Communication\MembershipSource;
use App\Models\Communication\Conversation;
use App\Models\Communication\ConversationMember;
use App\Models\User;
use App\Notifications\Communication\CommunicationNotification;
use App\Services\Communication\MemberEligibility;
use App\Services\Communication\MembershipWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/** Owners and admins (and scoped channel managers) add people to a group or channel. */
final class AddConversationMembersAction
{
    public function __construct(
        private readonly MemberEligibility $eligibility,
        private readonly MembershipWriter $writer,
        private readonly WriteAuditLogAction $audit,
    ) {}

    /**
     * @param  list<int>  $userIds
     * @return list<int> who joined
     */
    public function execute(User $actor, Conversation $conversation, array $userIds): array
    {
        if (! Gate::forUser($actor)->allows('manageMembers', $conversation)) {
            throw new AuthorizationException;
        }
        $users = $this->eligibility->resolve($actor, $userIds, $conversation);
        if ($conversation->type === ConversationType::Group) {
            $current = ConversationMember::query()->where('conversation_id', $conversation->getKey())->whereNull('left_at')->count();
            if ($current + $users->count() > MemberEligibility::maxGroupMembers()) {
                throw ValidationException::withMessages(['user_ids' => __('communication.errors.too_many_members', ['max' => MemberEligibility::maxGroupMembers()])]);
            }
        }

        $added = $this->writer->add($conversation, $users->modelKeys(), MembershipSource::Manual, $actor);
        if ($added === []) {
            return [];
        }
        $joined = $users->whereIn('id', $added)->values();
        $this->writer->note($conversation, $actor, 'member_added', MemberEligibility::names($joined));
        $this->writer->announce($conversation, $added, []);
        $this->audit->execute(AuditEventType::CommunicationMemberAdded, $actor, $conversation, $conversation->organization_id,
            null, ['user_ids' => $added, 'conversation_type' => $conversation->type->value]);

        $title = $conversation->channel?->name ?? (string) $conversation->title;
        Notification::send($joined, new CommunicationNotification(
            $conversation->type === ConversationType::Channel ? 'channel_invitation' : 'added_to_group',
            ['name' => $actor->name, 'group' => $title, 'channel' => $title], '/communication/messages/'.$conversation->getKey()));

        return $added;
    }
}
