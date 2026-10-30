<?php

declare(strict_types=1);

use App\Enums\AuditEventType;
use App\Enums\OrganizationScopeType;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserOrganizationScope;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Modules\Communication\Application\Actions\CreateChannelAction;
use Modules\Communication\Application\Actions\CreateGroupAction;
use Modules\Communication\Application\Actions\SendMessageAction;
use Modules\Communication\Application\Notifications\CommunicationNotification;
use Modules\Communication\Application\Services\ChannelMembershipSync;
use Modules\Communication\Application\Services\CommunicationInbox;
use Modules\Communication\Contracts\Data\CommunicationRoles;
use Modules\Communication\Domain\Models\Channel;
use Modules\Communication\Domain\Models\Conversation;
use Modules\Communication\Domain\Models\ConversationMember;
use Tests\Support\CommunicationScenario;

/*
 * Groups and channels: owner/admin/member rules, leaving and handover,
 * archiving, channel creation by scope, joining, and automatic membership
 * that follows the organizational assignment.
 */

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-18 09:00:00'));
    $this->s = CommunicationScenario::build();
    $this->manager = CommunicationScenario::employeeUser('Meron Manager', $this->s->orgA, $this->s->unitA1,
        [...CommunicationRoles::EMPLOYEE_PERMISSIONS, 'channels.create', 'channels.manage', 'channels.manage_members'], 'Comm Channel Manager');
});

afterEach(fn () => Carbon::setTestNow());

function cgGroup(object $test, ?User $owner = null, array $members = []): Conversation
{
    return app(CreateGroupAction::class)->execute($owner ?? $test->s->alice, ['title' => 'Transfer desk', 'member_ids' => array_map(fn (User $u) => $u->id, $members ?: [$test->s->bob])]);
}

function cgActive(Conversation $conversation): array
{
    return ConversationMember::query()->where('conversation_id', $conversation->id)->whereNull('left_at')->orderBy('user_id')->pluck('role', 'user_id')
        ->map(fn ($role) => $role->value)->all();
}

function cgChannel(object $test, array $data = [], ?User $actor = null): Channel
{
    return app(CreateChannelAction::class)->execute($actor ?? $test->manager, [
        'name' => 'Civil Service Bureau — all staff', 'type' => 'PUBLIC_ORGANIZATION', 'organization_id' => $test->s->orgA->id, ...$data,
    ]);
}

it('creates a group with its creator as owner, a system note, an audit entry and notifications', function (): void {
    Notification::fake();
    $this->actingAs($this->s->alice)->postJson(route('communication.api.groups.store'), [
        'title' => 'Transfer desk', 'member_ids' => [$this->s->bob->id, $this->s->carol->id],
    ])->assertCreated();

    $group = Conversation::query()->where('type', 'GROUP')->sole();
    expect(cgActive($group))->toBe([$this->s->alice->id => 'owner', $this->s->bob->id => 'member', $this->s->carol->id => 'member'])
        ->and($group->organization_id)->toBe($this->s->orgA->id)
        ->and($group->messages()->sole()->metadata)->toMatchArray(['system_event' => 'group_created', 'actor_name' => 'Alice Abebe'])
        ->and(AuditLog::query()->where('event_type', AuditEventType::CommunicationGroupCreated->value)->count())->toBe(1);
    Notification::assertSentTo([$this->s->bob, $this->s->carol], CommunicationNotification::class, fn ($n) => $n->kind === 'added_to_group');
    Notification::assertNotSentTo($this->s->alice, CommunicationNotification::class);
});

it('refuses anyone outside reach without saying who, and creates nothing', function (): void {
    $this->actingAs($this->s->alice)->postJson(route('communication.api.groups.store'), [
        'title' => 'Cross-bureau', 'member_ids' => [$this->s->bob->id, $this->s->dave->id],
    ])->assertUnprocessable()->assertJsonValidationErrors(['member_ids']);

    expect(Conversation::query()->where('type', 'GROUP')->count())->toBe(0);

    CommunicationScenario::setting('allow_employee_groups', false);
    $this->actingAs($this->s->alice)->postJson(route('communication.api.groups.store'), ['title' => 'Off', 'member_ids' => [$this->s->bob->id]])
        ->assertForbidden();
});

it('lets owners and admins manage members, never removes the owner, and keeps one row per person', function (): void {
    $group = cgGroup($this);

    $this->actingAs($this->s->bob)->postJson(route('communication.api.members.store', $group), ['user_ids' => [$this->s->carol->id]])->assertForbidden();
    $this->actingAs($this->s->alice)->postJson(route('communication.api.members.store', $group), ['user_ids' => [$this->s->carol->id]])
        ->assertOk()->assertJsonPath('added', 1);

    $this->actingAs($this->s->alice)->patchJson(route('communication.api.members.role', [$group, $this->s->bob]), ['role' => 'admin'])->assertOk();
    $this->actingAs($this->s->bob)->deleteJson(route('communication.api.members.destroy', [$group, $this->s->alice]))->assertUnprocessable();
    $this->actingAs($this->s->bob)->deleteJson(route('communication.api.members.destroy', [$group, $this->s->carol]))->assertOk();

    // Carol lost access at once and gets the same 404 as a stranger.
    $this->actingAs($this->s->carol)->getJson(route('communication.api.conversations.messages', $group))->assertNotFound();

    $this->actingAs($this->s->alice)->postJson(route('communication.api.members.store', $group), ['user_ids' => [$this->s->carol->id]])->assertOk();
    expect(ConversationMember::query()->where('conversation_id', $group->id)->where('user_id', $this->s->carol->id)->count())->toBe(1)
        ->and(cgActive($group)[$this->s->carol->id])->toBe('member');
});

it('starts a newcomer at the latest message and never counts system notes as unread', function (): void {
    $group = cgGroup($this);
    foreach (range(1, 3) as $i) {
        app(SendMessageAction::class)->execute($group, $this->s->alice, ['body' => "Note {$i}"]);
    }
    $this->actingAs($this->s->alice)->postJson(route('communication.api.members.store', $group), ['user_ids' => [$this->s->carol->id]])->assertOk();

    $inbox = app(CommunicationInbox::class);
    expect($inbox->memberships($this->s->carol)->where('conversation_members.conversation_id', $group->id)->value('unread_count'))->toBe(0)
        ->and($inbox->memberships($this->s->bob)->where('conversation_members.conversation_id', $group->id)->value('unread_count'))->toBe(3);
});

it('hands ownership to the longest-standing admin when the owner leaves, and archives when the last person leaves', function (): void {
    $group = cgGroup($this, members: [$this->s->bob, $this->s->carol]);
    $this->actingAs($this->s->alice)->patchJson(route('communication.api.members.role', [$group, $this->s->carol]), ['role' => 'admin'])->assertOk();

    $this->actingAs($this->s->alice)->postJson(route('communication.api.conversations.leave', $group))->assertOk();
    expect(cgActive($group))->toBe([$this->s->bob->id => 'member', $this->s->carol->id => 'owner']);

    $this->actingAs($this->s->bob)->postJson(route('communication.api.conversations.leave', $group))->assertOk();
    $this->actingAs($this->s->carol)->postJson(route('communication.api.conversations.leave', $group))->assertOk();
    expect($group->fresh()->archived_at)->not->toBeNull();
});

it('lets only the owner change roles, and transfers ownership as a pair', function (): void {
    $group = cgGroup($this, members: [$this->s->bob, $this->s->carol]);
    $this->actingAs($this->s->bob)->patchJson(route('communication.api.members.role', [$group, $this->s->carol]), ['role' => 'admin'])->assertForbidden();

    $this->actingAs($this->s->alice)->patchJson(route('communication.api.members.role', [$group, $this->s->bob]), ['role' => 'owner'])->assertOk();
    expect(cgActive($group))->toBe([$this->s->alice->id => 'admin', $this->s->bob->id => 'owner', $this->s->carol->id => 'member'])
        ->and(AuditLog::query()->where('event_type', AuditEventType::CommunicationMemberRoleChanged->value)->count())->toBe(1);
});

it('renames and archives a group; an archived group is read-only', function (): void {
    $group = cgGroup($this);
    $this->actingAs($this->s->bob)->patchJson(route('communication.api.conversations.update', $group), ['title' => 'Hijack'])->assertForbidden();
    $this->actingAs($this->s->alice)->patchJson(route('communication.api.conversations.update', $group), ['title' => 'Transfer desk 2026'])->assertOk();
    $this->actingAs($this->s->alice)->postJson(route('communication.api.conversations.archive', $group))->assertOk();

    expect($group->fresh()->title)->toBe('Transfer desk 2026');
    $this->actingAs($this->s->bob)->postJson(route('communication.api.messages.store', $group), ['body' => 'Still there?'])->assertForbidden();
    $this->actingAs($this->s->bob)->getJson(route('communication.api.conversations.messages', $group))->assertOk();
});

it('fills an organization channel from assignments, and only scoped managers create one', function (): void {
    $this->actingAs($this->s->alice)->postJson(route('communication.api.channels.store'), [
        'name' => 'All staff', 'type' => 'PUBLIC_ORGANIZATION', 'organization_id' => $this->s->orgA->id,
    ])->assertForbidden();

    $this->actingAs($this->manager)->postJson(route('communication.api.channels.store'), [
        'name' => 'All staff', 'type' => 'PUBLIC_ORGANIZATION', 'organization_id' => $this->s->orgA->id, 'auto_membership' => true,
    ])->assertCreated();

    $channel = Channel::query()->sole();
    $members = cgActive($channel->conversation);
    expect($members)->toHaveKeys([$this->s->alice->id, $this->s->bob->id, $this->s->carol->id, $this->manager->id])
        ->and($members)->not->toHaveKey($this->s->dave->id)
        ->and($members[$this->manager->id])->toBe('owner')
        ->and(ConversationMember::query()->where('conversation_id', $channel->conversation_id)->where('user_id', $this->s->alice->id)->value('source')->value)->toBe('AUTO');
});

it('lets employees create only their own private or project channels, and only when allowed', function (): void {
    $payload = ['name' => 'Digitization project', 'type' => 'PROJECT', 'organization_id' => $this->s->orgB->id, 'auto_membership' => true];
    $this->actingAs($this->s->alice)->postJson(route('communication.api.channels.store'), $payload)->assertForbidden();

    CommunicationScenario::setting('allow_employee_channels', true);
    $this->actingAs($this->s->alice)->postJson(route('communication.api.channels.store'), $payload)->assertCreated();

    $channel = Channel::query()->sole();
    expect($channel->organization_id)->toBe($this->s->orgA->id)
        ->and($channel->auto_membership)->toBeFalse()
        ->and(cgActive($channel->conversation))->toBe([$this->s->alice->id => 'owner']);
});

it('lets people join the organization channels they are placed in, but not others or after being removed', function (): void {
    $orgWide = cgChannel($this);
    $unitOnly = cgChannel($this, ['name' => 'HR Directorate', 'organization_unit_id' => $this->s->unitA1->id]);

    $this->actingAs($this->s->carol)->postJson(route('communication.api.channels.join', $orgWide))->assertOk();
    $this->actingAs($this->s->carol)->postJson(route('communication.api.channels.join', $unitOnly))->assertNotFound();
    $this->actingAs($this->s->dave)->postJson(route('communication.api.channels.join', $orgWide))->assertNotFound();

    $this->actingAs($this->manager)->deleteJson(route('communication.api.members.destroy', [$orgWide->conversation, $this->s->carol]))->assertOk();
    $this->actingAs($this->s->carol)->postJson(route('communication.api.channels.join', $orgWide))->assertUnprocessable();
});

it('lists joinable organization channels and your own, never other people\'s private channels', function (): void {
    cgChannel($this);
    cgChannel($this, ['name' => 'HR Directorate', 'organization_unit_id' => $this->s->unitA1->id]);
    CommunicationScenario::setting('allow_employee_channels', true);
    cgChannel($this, ['name' => 'Bob private', 'type' => 'PRIVATE'], $this->s->bob);

    $names = collect($this->actingAs($this->s->carol)->getJson(route('communication.api.channels.browse'))->assertOk()->json('data'))->pluck('name')->all();
    expect($names)->toBe(['Civil Service Bureau — all staff']);

    $names = collect($this->actingAs($this->s->bob)->getJson(route('communication.api.channels.browse'))->assertOk()->json('data'))->pluck('name')->sort()->values()->all();
    expect($names)->toBe(['Bob private', 'Civil Service Bureau — all staff', 'HR Directorate']);
});

it('moves automatic memberships with a transfer, and never pulls back someone who left', function (): void {
    // The manager runs channels in both bureaus; organization-level roles need an explicit scope (ADR 0003).
    foreach ([$this->s->orgA, $this->s->orgB] as $organization) {
        UserOrganizationScope::query()->create(['user_id' => $this->manager->id, 'organization_id' => $organization->id, 'scope_type' => OrganizationScopeType::Self, 'is_active' => true]);
    }
    app(OrganizationScopeService::class)->clearCache();
    $orgA = cgChannel($this, ['auto_membership' => true]);
    $orgB = app(CreateChannelAction::class)->execute($this->manager, ['name' => 'Health Bureau — all staff', 'type' => 'PUBLIC_ORGANIZATION', 'organization_id' => $this->s->orgB->id, 'auto_membership' => true]);

    // Carol transfers to the Health Bureau: the assignment observer queues her sync.
    $carolAssignment = $this->s->carol->employee->currentAssignment;
    $carolAssignment->update(['organization_id' => $this->s->orgB->id, 'organization_unit_id' => $this->s->unitB->id]);
    expect(cgActive($orgA->conversation))->not->toHaveKey($this->s->carol->id)
        ->and(cgActive($orgB->conversation))->toHaveKey($this->s->carol->id)
        ->and(ConversationMember::query()->where('conversation_id', $orgA->conversation_id)->where('user_id', $this->s->carol->id)->value('left_reason'))->toBe('SYNC');

    // Back again: re-added. Bob leaves on purpose: the nightly sync leaves him out.
    $carolAssignment->update(['organization_id' => $this->s->orgA->id, 'organization_unit_id' => $this->s->unitA2->id]);
    $this->actingAs($this->s->bob)->postJson(route('communication.api.conversations.leave', $orgA->conversation))->assertOk();
    app(ChannelMembershipSync::class)->syncChannel($orgA->fresh());

    expect(cgActive($orgA->conversation))->toHaveKey($this->s->carol->id)->not->toHaveKey($this->s->bob->id);
});

it('keeps people in a system-managed channel: they cannot leave, and the sync restores them', function (): void {
    $mandatory = cgChannel($this, ['name' => 'Bureau notices', 'type' => 'SYSTEM_MANAGED', 'auto_membership' => true, 'posting_policy' => 'ADMINS_ONLY']);

    $this->actingAs($this->s->bob)->postJson(route('communication.api.conversations.leave', $mandatory->conversation))->assertUnprocessable();
    $this->actingAs($this->s->bob)->postJson(route('communication.api.messages.store', $mandatory->conversation), ['body' => 'Hi'])->assertForbidden();
    $this->actingAs($this->manager)->deleteJson(route('communication.api.members.destroy', [$mandatory->conversation, $this->s->bob]))->assertUnprocessable();

    ConversationMember::query()->where('conversation_id', $mandatory->conversation_id)->where('user_id', $this->s->bob->id)->update(['left_at' => now(), 'left_reason' => 'LEFT']);
    $this->artisan('communication:sync-memberships')->assertSuccessful();
    expect(cgActive($mandatory->conversation))->toHaveKey($this->s->bob->id);
});

it('shows a large channel a page of members at a time, by name', function (): void {
    $channel = cgChannel($this, ['auto_membership' => true]);

    $page = $this->actingAs($this->s->alice)->getJson(route('communication.api.members.index', [$channel->conversation, 'q' => 'Car']))->assertOk();
    expect(collect($page->json('data'))->pluck('name')->all())->toBe(['Carol Chala']);
    $this->actingAs($this->s->dave)->getJson(route('communication.api.members.index', $channel->conversation))->assertNotFound();
});
