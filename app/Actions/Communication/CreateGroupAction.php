<?php

declare(strict_types=1);

namespace App\Actions\Communication;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\Communication\ConversationType;
use App\Enums\Communication\MemberRole;
use App\Enums\Communication\MembershipSource;
use App\Models\Communication\Conversation;
use App\Models\User;
use App\Notifications\Communication\CommunicationNotification;
use App\Services\Communication\CommunicationAccess;
use App\Services\Communication\CommunicationHierarchy;
use App\Services\Communication\CommunicationSettings;
use App\Services\Communication\MemberEligibility;
use App\Services\Communication\MembershipWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * An employee-created group: the creator is its owner, everyone else a member.
 * Members must be within the creator's reach (MemberEligibility); the group
 * belongs to the creator's organization for scoped management.
 */
final class CreateGroupAction
{
    public function __construct(
        private readonly CommunicationAccess $access,
        private readonly CommunicationSettings $settings,
        private readonly CommunicationHierarchy $hierarchy,
        private readonly MemberEligibility $eligibility,
        private readonly MembershipWriter $writer,
        private readonly WriteAuditLogAction $audit,
    ) {}

    /** @param array{title: string, description?: ?string, member_ids: list<int>} $data */
    public function execute(User $actor, array $data): Conversation
    {
        if (! $this->access->canUse($actor) || ! $actor->can('groups.create') || ! $this->settings->allowEmployeeGroups()) {
            throw new AuthorizationException;
        }
        $members = $this->eligibility->resolve($actor, $data['member_ids'], null, 'member_ids');
        if ($members->count() + 1 > MemberEligibility::maxGroupMembers()) {
            throw ValidationException::withMessages(['member_ids' => __('communication.errors.too_many_members', ['max' => MemberEligibility::maxGroupMembers()])]);
        }

        $conversation = DB::transaction(function () use ($actor, $data): Conversation {
            $conversation = (new Conversation)->forceFill([
                'type' => ConversationType::Group,
                'title' => trim($data['title']),
                'description' => filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
                'created_by' => $actor->getKey(),
                'organization_id' => $this->hierarchy->assignmentOf($actor)?->organization_id,
                'last_message_at' => now(),
            ]);
            $conversation->save();

            return $conversation;
        });

        $this->writer->add($conversation, [$actor->getKey()], MembershipSource::Manual, $actor, MemberRole::Owner);
        $added = $this->writer->add($conversation, $members->modelKeys(), MembershipSource::Manual, $actor);
        $this->writer->note($conversation, $actor, 'group_created');
        $this->writer->announce($conversation, [(int) $actor->getKey(), ...$added], []);

        $this->audit->execute(AuditEventType::CommunicationGroupCreated, $actor, $conversation, $conversation->organization_id,
            null, ['title' => $conversation->title, 'member_count' => count($added) + 1]);
        Notification::send($members, new CommunicationNotification('added_to_group',
            ['name' => $actor->name, 'group' => $conversation->title], '/communication/messages/'.$conversation->getKey()));

        return $conversation;
    }
}
