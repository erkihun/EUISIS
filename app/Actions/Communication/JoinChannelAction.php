<?php

declare(strict_types=1);

namespace App\Actions\Communication;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\Communication\MembershipSource;
use App\Models\Communication\Channel;
use App\Models\Communication\ConversationMember;
use App\Models\User;
use App\Services\Communication\MembershipWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Joins an organization channel the person is placed in. Someone an admin
 * removed cannot rejoin by themselves; they are added back by an admin.
 */
final class JoinChannelAction
{
    public function __construct(
        private readonly MembershipWriter $writer,
        private readonly WriteAuditLogAction $audit,
    ) {}

    public function execute(User $user, Channel $channel): void
    {
        if (! Gate::forUser($user)->allows('join', $channel)) {
            throw new AuthorizationException;
        }
        $row = ConversationMember::query()->where('conversation_id', $channel->conversation_id)->where('user_id', $user->getKey())->first();
        if ($row?->left_at === null && $row !== null) {
            return;
        }
        if ($row?->left_reason === 'REMOVED') {
            throw ValidationException::withMessages(['channel' => __('communication.errors.removed_from_channel')]);
        }

        $conversation = $channel->conversation;
        $this->writer->add($conversation, [$user->getKey()], MembershipSource::Manual, $user);
        $this->writer->note($conversation, $user, 'member_joined');
        $this->writer->announce($conversation, [(int) $user->getKey()], []);
        $this->audit->execute(AuditEventType::CommunicationMemberAdded, $user, $conversation, $channel->organization_id,
            null, ['user_ids' => [$user->getKey()], 'joined' => true]);
    }
}
