<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Communication\Application\Actions\CreateGroupAction;
use Modules\Communication\Application\Actions\MarkConversationReadAction;
use Modules\Communication\Application\Actions\SendMessageAction;
use Modules\Communication\Application\Actions\StartDirectConversationAction;
use Modules\Communication\Application\Events\UnreadCommunicationCountUpdated;
use Tests\Support\CommunicationScenario;

/*
 * Communication is entered from the top bar, never from the sidebar. Every
 * page carries only a small `communication` prop (on/off, unread total,
 * realtime); the badge follows `unread.changed` on the person's own channel.
 */

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-18 09:00:00'));
    $this->s = CommunicationScenario::build();
    Queue::fake();
    config(['broadcasting.default' => 'reverb']);
});

afterEach(fn () => Carbon::setTestNow());

/** @return list<string> */
function signalledChannels(): array
{
    $names = [];
    Event::assertDispatched(UnreadCommunicationCountUpdated::class, function (UnreadCommunicationCountUpdated $event) use (&$names): bool {
        foreach ($event->broadcastOn() as $channel) {
            $names[] = $channel->name;
        }

        return true;
    });

    return $names;
}

it('shares only a small communication prop, and none without access', function (): void {
    $this->actingAs($this->s->alice)->get(route('communication.messages.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Communication/Messages')
            ->where('communication.enabled', true)
            ->where('communication.unread_count', 0)
            ->where('communication.realtime', true)
            ->missing('communication.conversations')
            ->where('communication.nav.announcements', true)
            ->where('communication.nav.channels', true));

    $outsider = CommunicationScenario::employeeUser('No Access', $this->s->orgA, $this->s->unitA1, ['dashboard.view'], 'No Communication Role');
    $this->actingAs($outsider)->get(route('communication.messages.index'))->assertForbidden();
});

it('tells both people in a direct conversation that their unread total changed', function (): void {
    $conversation = app(StartDirectConversationAction::class)->execute($this->s->alice, $this->s->bob);
    Event::fake([UnreadCommunicationCountUpdated::class]);

    app(SendMessageAction::class)->execute($conversation, $this->s->alice, ['body' => 'Hello']);

    expect(signalledChannels())->toContain('private-communication.user.'.$this->s->alice->id, 'private-communication.user.'.$this->s->bob->id);
});

it('tells the other members of a group, and the reader when they read', function (): void {
    $group = app(CreateGroupAction::class)->execute($this->s->alice, ['title' => 'Desk', 'member_ids' => [$this->s->bob->id, $this->s->carol->id]]);
    Event::fake([UnreadCommunicationCountUpdated::class]);

    app(SendMessageAction::class)->execute($group, $this->s->alice, ['body' => 'Team update']);
    expect(signalledChannels())->toContain('private-communication.user.'.$this->s->bob->id, 'private-communication.user.'.$this->s->carol->id);

    Event::fake([UnreadCommunicationCountUpdated::class]);
    app(MarkConversationReadAction::class)->execute($group, $this->s->bob);
    expect(signalledChannels())->toBe(['private-communication.user.'.$this->s->bob->id]);
});

it('keeps Communication out of the sidebar and puts it first in the top bar', function (): void {
    $sidebar = file_get_contents(resource_path('js/Components/AppSidebar.tsx'));
    $header = file_get_contents(resource_path('js/Components/AppHeader.tsx'));

    expect($sidebar)->not->toContain("'communication.")
        ->and(strpos($header, '<CommunicationButton />'))->toBeLessThan(strpos($header, '<NotificationBell />'))
        ->and(strpos($header, '<NotificationBell />'))->toBeLessThan(strpos($header, '<ThemeToggle'))
        ->and(strpos($header, '<ThemeToggle'))->toBeLessThan(strpos($header, '<LanguageSwitcher'));
});

it('shares the workspace navigation only on Communication pages', function (): void {
    $this->actingAs($this->s->alice)->get(route('communication.preferences.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('communication.nav.moderate', false)->where('communication.nav.settings', false));

    $this->actingAs($this->s->alice)->followingRedirects()->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('communication.nav', null));
});

it('searches inside one conversation for its members only, never deleted messages', function (): void {
    $conversation = app(StartDirectConversationAction::class)->execute($this->s->alice, $this->s->bob);
    app(SendMessageAction::class)->execute($conversation, $this->s->alice, ['body' => 'The approval letter is ready']);
    app(SendMessageAction::class)->execute($conversation, $this->s->bob, ['body' => 'Thanks, see you on Thursday']);
    $gone = app(SendMessageAction::class)->execute($conversation, $this->s->bob, ['body' => 'Approval draft (old)']);
    $gone->delete();

    $this->actingAs($this->s->bob)->getJson(route('communication.api.conversations.search', [$conversation, 'q' => 'APPROVAL']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.body', 'The approval letter is ready');

    $this->actingAs($this->s->bob)->getJson(route('communication.api.conversations.search', [$conversation, 'q' => 'a']))->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($this->s->bob)->getJson(route('communication.api.conversations.search', [$conversation, 'q' => '100%']))->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($this->s->carol)->getJson(route('communication.api.conversations.search', [$conversation, 'q' => 'approval']))->assertNotFound();
});

it('queues no broadcast while the broadcaster only logs', function (): void {
    config(['broadcasting.default' => 'log']);
    $conversation = app(StartDirectConversationAction::class)->execute($this->s->alice, $this->s->bob);
    Event::fake([UnreadCommunicationCountUpdated::class]);

    app(SendMessageAction::class)->execute($conversation, $this->s->alice, ['body' => 'Hello']);

    Event::assertNotDispatched(UnreadCommunicationCountUpdated::class);
});

it('finds a colleague in reach by the account name as well as the employee record', function (): void {
    $this->s->bob->forceFill(['name' => 'Robert Accountname'])->save();
    $this->s->dave->forceFill(['name' => 'Robert Farbureau'])->save();

    $found = $this->actingAs($this->s->alice)->getJson(route('communication.api.directory.search', ['q' => 'robert']))->assertOk()->json('data');

    expect(array_column($found, 'id'))->toBe([$this->s->bob->id]);
});
