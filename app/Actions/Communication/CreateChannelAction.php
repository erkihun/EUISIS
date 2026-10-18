<?php

declare(strict_types=1);

namespace App\Actions\Communication;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Enums\Communication\ChannelPostingPolicy;
use App\Enums\Communication\ChannelType;
use App\Enums\Communication\ConversationType;
use App\Enums\Communication\MemberRole;
use App\Enums\Communication\MembershipSource;
use App\Events\Communication\ChannelCreated;
use App\Jobs\Communication\SyncChannelMemberships;
use App\Models\Communication\Channel;
use App\Models\Communication\Conversation;
use App\Models\OrganizationUnit;
use App\Models\User;
use App\Services\Communication\CommunicationBroadcaster;
use App\Services\Communication\CommunicationHierarchy;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Services\Communication\MembershipWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates a channel and its conversation. The creator becomes its owner.
 *
 * - Organization, system-managed and city-wide channels need channels.create
 *   in that organization's scope (system-managed: channels.manage). They may
 *   be bound to a unit, include descendants, and fill themselves from the
 *   organizational assignment (auto_membership).
 * - When the system allows it, any user may create a PRIVATE or PROJECT
 *   channel of their own: it belongs to their organization, has no scope and
 *   no automatic membership.
 */
final class CreateChannelAction
{
    public function __construct(
        private readonly CommunicationHierarchy $hierarchy,
        private readonly OrganizationScopeService $scope,
        private readonly MembershipWriter $writer,
        private readonly CommunicationBroadcaster $broadcaster,
        private readonly WriteAuditLogAction $audit,
    ) {}

    /**
     * @param  array{name: string, name_am?: ?string, description?: ?string, type: string, organization_id?: ?string,
     *     organization_unit_id?: ?string, include_descendants?: bool, auto_membership?: bool, remove_on_transfer?: bool, posting_policy?: ?string}  $data
     */
    public function execute(User $actor, array $data): Channel
    {
        $type = ChannelType::from($data['type']);
        $unitId = $data['organization_unit_id'] ?? null;
        $orgId = $data['organization_id'] ?? null;
        if ($unitId !== null) {
            $unitOrg = OrganizationUnit::query()->whereKey($unitId)->value('organization_id');
            if ($unitOrg === null || ($orgId !== null && (string) $unitOrg !== (string) $orgId)) {
                throw ValidationException::withMessages(['organization_unit_id' => __('communication.errors.unit_outside_organization')]);
            }
            $orgId = (string) $unitOrg;
        }

        $managed = $this->scope->canExercisePermission($actor, 'channels.create', $orgId)
            && ($type !== ChannelType::SystemManaged || $this->scope->canExercisePermission($actor, 'channels.manage', $orgId));
        if (! $managed) {
            // An employee's own channel: private or project, in their organization, never automatic.
            if (! Gate::forUser($actor)->allows('create', [Channel::class, null, $type]) || ! in_array($type, [ChannelType::Private, ChannelType::Project], true)) {
                throw new AuthorizationException;
            }
            $orgId = $this->hierarchy->assignmentOf($actor)?->organization_id;
            $unitId = null;
            $data['auto_membership'] = false;
            $data['include_descendants'] = false;
        }
        $auto = (bool) ($data['auto_membership'] ?? false) && in_array($type, [ChannelType::PublicOrganization, ChannelType::SystemManaged], true);

        $channel = DB::transaction(function () use ($actor, $data, $type, $orgId, $unitId, $auto): Channel {
            $conversation = (new Conversation)->forceFill([
                'type' => ConversationType::Channel,
                'title' => trim($data['name']),
                'description' => filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
                'created_by' => $actor->getKey(),
                'organization_id' => $orgId,
                'last_message_at' => now(),
            ]);
            $conversation->save();

            $channel = (new Channel)->forceFill([
                'conversation_id' => $conversation->getKey(),
                'slug' => $this->uniqueSlug($data['name'], $orgId),
                'name' => trim($data['name']),
                'name_am' => filled($data['name_am'] ?? null) ? trim((string) $data['name_am']) : null,
                'description' => $conversation->description,
                'type' => $type,
                'organization_id' => $orgId,
                'organization_unit_id' => $unitId,
                'include_descendants' => (bool) ($data['include_descendants'] ?? false),
                'auto_membership' => $auto,
                'remove_on_transfer' => (bool) ($data['remove_on_transfer'] ?? true),
                'posting_policy' => ChannelPostingPolicy::tryFrom((string) ($data['posting_policy'] ?? '')) ?? ChannelPostingPolicy::AllMembers,
                'created_by' => $actor->getKey(),
            ]);
            $channel->save();

            return $channel;
        });
        $conversation = $channel->conversation;

        $this->writer->add($conversation, [$actor->getKey()], MembershipSource::Manual, $actor, MemberRole::Owner);
        $this->writer->note($conversation, $actor, 'channel_created');
        $this->audit->execute(AuditEventType::CommunicationChannelCreated, $actor, $channel, $orgId, null, [
            'name' => $channel->name, 'type' => $type->value, 'organization_unit_id' => $unitId,
            'include_descendants' => $channel->include_descendants, 'auto_membership' => $auto, 'posting_policy' => $channel->posting_policy->value,
        ]);
        $this->broadcaster->send(new ChannelCreated($channel->getKey(), $conversation->getKey(), [(int) $actor->getKey()]));

        if ($auto) {
            SyncChannelMemberships::dispatch($channel->getKey(), (int) $actor->getKey())->afterCommit();
        }

        return $channel;
    }

    private function uniqueSlug(string $name, ?string $orgId): string
    {
        $base = Str::limit(Str::slug($name) ?: 'channel', 70, '');
        $slug = $base;
        for ($n = 2; Channel::query()->where('slug', $slug)->where('organization_id', $orgId)->exists(); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }
}
