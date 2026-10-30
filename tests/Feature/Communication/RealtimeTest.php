<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Modules\Communication\Application\Actions\SendMessageAction;
use Modules\Communication\Application\Actions\StartDirectConversationAction;
use Modules\Communication\Application\Events\MessageSent;
use Modules\Communication\Application\Events\UserTyping;
use Tests\Support\CommunicationScenario;

/*
 * Realtime is an addition to HTTP: channels admit current members only (no
 * Super Admin bypass), the auth endpoint sits behind the page middleware, and
 * events carry ids and small facts on the right channels.
 */

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-18 09:00:00'));
    $this->s = CommunicationScenario::build();
    Queue::fake(); // nothing here may try to reach a socket server

    // Channel callbacks bind to the broadcaster resolved at boot (null in tests);
    // switch to Reverb with throwaway keys and register them again.
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    Broadcast::forgetDrivers();
    require base_path('routes/channels.php');
});

afterEach(fn () => Carbon::setTestNow());

function rtAuth(object $test, User $user, string $channel): TestResponse
{
    return $test->actingAs($user)->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-'.$channel]);
}

it('admits members to a conversation channel and refuses everyone else, Super Admin included', function (): void {
    $conversation = app(StartDirectConversationAction::class)->execute($this->s->alice, $this->s->bob);
    $channel = 'communication.conversation.'.$conversation->id;

    rtAuth($this, $this->s->alice, $channel)->assertOk()->assertJsonStructure(['auth']);
    rtAuth($this, $this->s->carol, $channel)->assertForbidden();

    $root = CommunicationScenario::employeeUser('Root Admin', $this->s->orgA, $this->s->unitA1);
    $root->assignRole(CommunicationScenario::role('Super Admin', ['communication.access']));
    rtAuth($this, $root, $channel)->assertForbidden();

    rtAuth($this, $this->s->alice, 'communication.conversation.not-a-uuid')->assertForbidden();
});

it('keeps a personal channel to its owner and closes channels when the module is off', function (): void {
    rtAuth($this, $this->s->alice, 'communication.user.'.$this->s->alice->id)->assertOk();
    rtAuth($this, $this->s->alice, 'communication.user.'.$this->s->bob->id)->assertForbidden();

    CommunicationScenario::setting('communication_enabled', false);
    rtAuth($this, $this->s->alice, 'communication.user.'.$this->s->alice->id)->assertForbidden();
});

it('requires a signed-in session for channel authorization', function (): void {
    $this->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-communication.user.'.$this->s->alice->id])
        ->assertUnauthorized();
});

it('broadcasts a sent message on its conversation channel, after commit, on the configured queue', function (): void {
    config(['communication.broadcast_queue' => 'broadcasts']);
    $conversation = app(StartDirectConversationAction::class)->execute($this->s->alice, $this->s->bob);
    Event::fake([MessageSent::class]);

    app(SendMessageAction::class)->execute($conversation, $this->s->alice, ['body' => 'Hello']);

    Event::assertDispatched(MessageSent::class, function (MessageSent $event) use ($conversation): bool {
        return $event->broadcastOn()[0]->name === 'private-communication.conversation.'.$conversation->id
            && $event->broadcastWith()['message']['body'] === 'Hello'
            && $event->afterCommit === true
            && $event->broadcastQueue() === 'broadcasts';
    });
});

it('sends typing to the other members only, immediately, and never to the typist', function (): void {
    $conversation = app(StartDirectConversationAction::class)->execute($this->s->alice, $this->s->bob);
    Event::fake([UserTyping::class]);

    $this->actingAs($this->s->alice)->postJson(route('communication.api.conversations.typing', $conversation))->assertOk();

    Event::assertDispatched(UserTyping::class, fn (UserTyping $event): bool => $event instanceof ShouldBroadcastNow
        && $event->broadcastWith() === ['conversation_id' => $conversation->id, 'user_id' => $this->s->alice->id, 'name' => $this->s->alice->name]);
    $this->actingAs($this->s->carol)->postJson(route('communication.api.conversations.typing', $conversation))->assertNotFound();
});

it('queues the broadcast instead of holding up the send', function (): void {
    $conversation = app(StartDirectConversationAction::class)->execute($this->s->alice, $this->s->bob);

    $this->actingAs($this->s->alice)->postJson(route('communication.api.messages.store', $conversation), ['body' => 'Still works', 'client_message_id' => 'rt-1'])
        ->assertCreated();
    Queue::assertPushed(BroadcastEvent::class);
});
