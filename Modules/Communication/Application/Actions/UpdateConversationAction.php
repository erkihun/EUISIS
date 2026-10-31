<?php

declare(strict_types=1);

namespace Modules\Communication\Application\Actions;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Communication\Domain\Enums\ChannelPostingPolicy;
use Modules\Communication\Domain\Enums\ConversationType;
use Modules\Communication\Domain\Models\Conversation;

/**
 * Renames and describes a group (owners and admins), or a channel (its owners
 * and admins, or a scoped channel manager), including a channel's Amharic
 * name and who may post. Scope and automatic membership are not editable
 * here: a channel bound to the wrong unit is archived and created again.
 */
final class UpdateConversationAction
{
    public function __construct(private readonly WriteAuditLogAction $audit) {}

    /** @param array{title?: string, name_am?: ?string, description?: ?string, posting_policy?: string} $data */
    public function execute(User $actor, Conversation $conversation, array $data): Conversation
    {
        $channel = $conversation->type === ConversationType::Channel ? $conversation->channel : null;
        $allowed = $channel !== null ? Gate::forUser($actor)->allows('update', $channel) : Gate::forUser($actor)->allows('update', $conversation);
        if (! $allowed) {
            throw new AuthorizationException;
        }

        $before = ['title' => $conversation->title, 'description' => $conversation->description]
            + ($channel ? ['name_am' => $channel->name_am, 'posting_policy' => $channel->posting_policy->value] : []);

        DB::transaction(function () use ($conversation, $channel, $data): void {
            if (array_key_exists('title', $data)) {
                $conversation->title = trim((string) $data['title']);
            }
            if (array_key_exists('description', $data)) {
                $conversation->description = filled($data['description']) ? trim((string) $data['description']) : null;
            }
            $conversation->save();

            if ($channel !== null) {
                $channel->forceFill([
                    'name' => $conversation->title,
                    'description' => $conversation->description,
                    'name_am' => array_key_exists('name_am', $data) ? (filled($data['name_am']) ? trim((string) $data['name_am']) : null) : $channel->name_am,
                    'posting_policy' => isset($data['posting_policy']) ? ChannelPostingPolicy::from($data['posting_policy']) : $channel->posting_policy,
                ])->save();
            }
        });

        $after = ['title' => $conversation->title, 'description' => $conversation->description]
            + ($channel ? ['name_am' => $channel->name_am, 'posting_policy' => $channel->posting_policy->value] : []);
        $this->audit->execute($channel ? AuditEventType::CommunicationChannelUpdated : AuditEventType::CommunicationGroupUpdated,
            $actor, $channel ?? $conversation, $conversation->organization_id, $before, $after);

        return $conversation;
    }
}
