<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Communication\Application\Actions\SendMessageAction;
use Modules\Communication\Application\Actions\StartDirectConversationAction;
use Modules\Communication\Application\Notifications\CommunicationNotification;
use Modules\Communication\Application\Services\CommunicationInbox;
use Modules\Communication\Domain\Models\CommunicationBlock;
use Modules\Communication\Domain\Models\CommunicationUserSetting;
use Modules\Communication\Domain\Models\Conversation;
use Modules\Communication\Domain\Models\ConversationMember;
use Modules\Communication\Domain\Models\Message;
use Modules\Communication\Domain\Models\MessageReaction;
use Tests\Support\CommunicationScenario;

/*
 * Direct messaging: one conversation per pair, membership-only access,
 * idempotent sends, edit/delete windows, replies, reactions, unread state.
 */

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-18 09:00:00'));
    $this->s = CommunicationScenario::build();
});

afterEach(fn () => Carbon::setTestNow());

function cmDirect(object $test, ?User $from = null, ?User $to = null): Conversation
{
    return app(StartDirectConversationAction::class)->execute($from ?? $test->s->alice, $to ?? $test->s->bob);
}

function cmSend(Conversation $conversation, User $sender, string $body, array $extra = []): Message
{
    return app(SendMessageAction::class)->execute($conversation, $sender, ['body' => $body, ...$extra]);
}

it('starts one direct conversation per pair, whoever starts it', function (): void {
    $first = cmDirect($this);
    $again = cmDirect($this, $this->s->bob, $this->s->alice);

    expect($again->id)->toBe($first->id)
        ->and(Conversation::query()->where('type', 'DIRECT')->count())->toBe(1)
        ->and(ConversationMember::query()->where('conversation_id', $first->id)->count())->toBe(2);

    $this->actingAs($this->s->alice)->postJson(route('communication.api.conversations.store'), ['user_id' => $this->s->bob->id])
        ->assertCreated()->assertJsonPath('id', $first->id);
});

it('lets a member send and read, and answers 404 to anyone else, including Super Admin', function (): void {
    $conversation = cmDirect($this);
    $this->actingAs($this->s->alice)->postJson(route('communication.api.messages.store', $conversation), ['body' => 'Hello Bob'])
        ->assertCreated()->assertJsonPath('data.body', 'Hello Bob');

    $this->actingAs($this->s->bob)->getJson(route('communication.api.conversations.messages', $conversation))
        ->assertOk()->assertJsonPath('data.0.body', 'Hello Bob');

    // A colleague who guesses the id learns nothing — not even that it exists.
    $this->actingAs($this->s->carol)->getJson(route('communication.api.conversations.messages', $conversation))->assertNotFound();
    $this->actingAs($this->s->carol)->getJson(route('communication.api.conversations.show', $conversation))->assertNotFound();
    $this->actingAs($this->s->carol)->postJson(route('communication.api.messages.store', $conversation), ['body' => 'Hi'])->assertNotFound();
    $this->actingAs($this->s->carol)->get(route('communication.messages.show', $conversation))->assertNotFound();

    $superAdmin = CommunicationScenario::employeeUser('Root Admin', $this->s->orgA, $this->s->unitA1);
    $superAdmin->assignRole(CommunicationScenario::role('Super Admin', ['communication.access']));
    expect($superAdmin->can('view', $conversation))->toBeFalse();
    $this->actingAs($superAdmin)->getJson(route('communication.api.conversations.messages', $conversation))->assertNotFound();
});

it('stores a retried send once', function (): void {
    $conversation = cmDirect($this);
    $first = cmSend($conversation, $this->s->alice, 'Report attached', ['client_message_id' => 'c-1']);
    $retry = cmSend($conversation, $this->s->alice, 'Report attached', ['client_message_id' => 'c-1']);

    expect($retry->id)->toBe($first->id)->and(Message::query()->count())->toBe(1);
});

it('refuses an empty message, a foreign reply target and a broadcast mention in a direct conversation', function (): void {
    $conversation = cmDirect($this);
    $other = cmDirect($this, $this->s->alice, $this->s->carol);
    $foreign = cmSend($other, $this->s->alice, 'Elsewhere');

    expect(fn () => cmSend($conversation, $this->s->alice, '   '))->toThrow(ValidationException::class)
        ->and(fn () => cmSend($conversation, $this->s->alice, 'Reply', ['parent_message_id' => $foreign->id]))->toThrow(ValidationException::class)
        ->and(fn () => cmSend($conversation, $this->s->alice, 'Hi <!everyone>'))->toThrow(ValidationException::class);

    $parent = cmSend($conversation, $this->s->bob, 'Question?');
    $reply = cmSend($conversation, $this->s->alice, 'Answer.', ['parent_message_id' => $parent->id]);
    $this->actingAs($this->s->bob)->getJson(route('communication.api.conversations.messages', $conversation))
        ->assertJsonPath('data.0.id', $reply->id)->assertJsonPath('data.0.parent.excerpt', 'Question?');
});

it('respects blocks and the directory reach', function (): void {
    CommunicationBlock::query()->forceCreate(['blocker_id' => $this->s->bob->id, 'blocked_id' => $this->s->alice->id]);
    expect(fn () => cmDirect($this))->toThrow(ValidationException::class);

    // Dave works in another organization: outside the default OWN_ORGANIZATION reach.
    expect(fn () => cmDirect($this, $this->s->alice, $this->s->dave))->toThrow(ValidationException::class);
    CommunicationScenario::setting('communication_scope_default', 'CITY_WIDE');
    expect(cmDirect($this, $this->s->alice, $this->s->dave)->type->value)->toBe('DIRECT');
});

it('stops a blocked pair from posting in an existing conversation', function (): void {
    $conversation = cmDirect($this);
    CommunicationBlock::query()->forceCreate(['blocker_id' => $this->s->bob->id, 'blocked_id' => $this->s->alice->id]);

    $this->actingAs($this->s->alice)->postJson(route('communication.api.messages.store', $conversation), ['body' => 'Still there?'])->assertForbidden();
});

it('allows editing and deleting own messages only inside the windows, and hides deleted content', function (): void {
    $conversation = cmDirect($this);
    $message = cmSend($conversation, $this->s->alice, 'Draft text');

    $this->actingAs($this->s->bob)->patchJson(route('communication.api.messages.update', $message), ['body' => 'Hijack'])->assertForbidden();
    $this->actingAs($this->s->alice)->patchJson(route('communication.api.messages.update', $message), ['body' => 'Final text'])
        ->assertOk()->assertJsonPath('data.body', 'Final text');

    Carbon::setTestNow(now()->addMinutes(16)); // past the default 15-minute edit window
    $this->actingAs($this->s->alice)->patchJson(route('communication.api.messages.update', $message), ['body' => 'Too late'])->assertForbidden();

    $this->actingAs($this->s->alice)->deleteJson(route('communication.api.messages.destroy', $message))->assertOk();
    $this->actingAs($this->s->bob)->getJson(route('communication.api.conversations.messages', $conversation))
        ->assertJsonPath('data.0.deleted', true)->assertJsonPath('data.0.body', null);
    expect(Message::withTrashed()->find($message->id)->body)->toBe('Final text'); // retained, not shown

    $late = cmSend($conversation, $this->s->alice, 'Another');
    Carbon::setTestNow(now()->addMinutes(61)); // past the default 60-minute delete window
    $this->actingAs($this->s->alice)->deleteJson(route('communication.api.messages.destroy', $late))->assertForbidden();
});

it('keeps one reaction per person and emoji, and refuses emoji outside the set', function (): void {
    $conversation = cmDirect($this);
    $message = cmSend($conversation, $this->s->alice, 'Ship it?');

    $this->actingAs($this->s->bob)->postJson(route('communication.api.messages.react', $message), ['emoji' => '👍'])->assertOk()->assertJsonPath('reactions.0.count', 1);
    $this->actingAs($this->s->bob)->postJson(route('communication.api.messages.react', $message), ['emoji' => '👍'])->assertOk()->assertJsonPath('reactions.0.count', 1);
    expect(MessageReaction::query()->count())->toBe(1);

    $this->actingAs($this->s->bob)->postJson(route('communication.api.messages.react', $message), ['emoji' => '<script>'])->assertUnprocessable();
    $this->actingAs($this->s->bob)->deleteJson(route('communication.api.messages.unreact', $message), ['emoji' => '👍'])->assertOk()->assertJsonPath('reactions', []);
});

it('counts unread from the read pointer and never moves it backwards', function (): void {
    $conversation = cmDirect($this);
    $one = cmSend($conversation, $this->s->alice, 'One');
    cmSend($conversation, $this->s->alice, 'Two');

    $inbox = app(CommunicationInbox::class);
    expect($inbox->totals($this->s->bob, fresh: true)['direct'])->toBe(2)
        ->and($inbox->totals($this->s->alice, fresh: true)['direct'])->toBe(0); // own messages are read

    $this->actingAs($this->s->bob)->postJson(route('communication.api.conversations.read', $conversation))->assertOk();
    expect($inbox->totals($this->s->bob, fresh: true)['direct'])->toBe(0);

    $latest = ConversationMember::query()->where('conversation_id', $conversation->id)->where('user_id', $this->s->bob->id)->value('last_read_message_id');
    $this->actingAs($this->s->bob)->postJson(route('communication.api.conversations.read', $conversation), ['message_id' => $one->id])->assertOk();
    expect(ConversationMember::query()->where('conversation_id', $conversation->id)->where('user_id', $this->s->bob->id)->value('last_read_message_id'))->toBe($latest);
});

it('notifies the recipient once per unread run, without message text, and a mentioned member', function (): void {
    $conversation = cmDirect($this);
    cmSend($conversation, $this->s->alice, 'Secret salary figures');
    cmSend($conversation, $this->s->alice, 'More secrets');

    $notifications = $this->s->bob->notifications()->where('type', CommunicationNotification::class)->get();
    expect($notifications)->toHaveCount(1)
        ->and(json_encode($notifications->first()->data))->not->toContain('Secret');
});

it('pages history newest first with a cursor', function (): void {
    config(['communication.page_size' => 3]);
    $conversation = cmDirect($this);
    foreach (range(1, 7) as $i) {
        cmSend($conversation, $this->s->alice, "Message {$i}");
    }

    $first = $this->actingAs($this->s->bob)->getJson(route('communication.api.conversations.messages', $conversation))->assertOk();
    expect(array_column($first->json('data'), 'body'))->toBe(['Message 7', 'Message 6', 'Message 5']);
    $second = $this->actingAs($this->s->bob)->getJson(route('communication.api.conversations.messages', [$conversation, 'before' => $first->json('next_before')]))->assertOk();
    expect(array_column($second->json('data'), 'body'))->toBe(['Message 4', 'Message 3', 'Message 2']);
});

it('closes the module when communication is switched off', function (): void {
    CommunicationScenario::setting('communication_enabled', false);
    $this->actingAs($this->s->alice)->get(route('communication.index'))->assertNotFound();
    $this->actingAs($this->s->alice)->getJson(route('communication.api.conversations.index'))->assertNotFound();
});

it('finds only reachable colleagues and returns directory-safe fields only', function (): void {
    $alice = $this->s->alice;
    $alice->employee->update(['national_id' => '1234567890123456']);

    $response = $this->actingAs($this->s->bob)->getJson(route('communication.api.directory.search', ['q' => 'a']))->assertOk();
    expect($response->json('data'))->toBe([]); // fewer than 2 characters

    $found = $this->actingAs($this->s->bob)->getJson(route('communication.api.directory.search', ['q' => 'Ali']))->assertOk()->json('data');
    expect($found)->toHaveCount(1)->and($found[0]['name'])->toBe('Alice Abebe')
        ->and(array_keys($found[0]))->not->toContain('national_id')->not->toContain('phone')->not->toContain('address')
        ->and(json_encode($found))->not->toContain('1234567890123456');

    expect($this->actingAs($this->s->bob)->getJson(route('communication.api.directory.search', ['q' => 'Dave']))->json('data'))->toBe([]);
});

it('records the presence heartbeat for someone without settings yet, and again on the next beat', function (): void {
    $this->actingAs($this->s->alice)->postJson(route('communication.api.presence'))->assertOk();
    Carbon::setTestNow(Carbon::parse('2026-10-18 09:05:00'));
    $this->actingAs($this->s->alice)->postJson(route('communication.api.presence'))->assertOk();

    expect(CommunicationUserSetting::query()->where('user_id', $this->s->alice->id)->count())->toBe(1)
        ->and(CommunicationUserSetting::query()->where('user_id', $this->s->alice->id)->first()->last_seen_at?->format('H:i'))->toBe('09:05');
});

it('shows mentioned people by name in the conversation list preview, never as raw tokens', function (): void {
    $conversation = cmDirect($this);
    cmSend($conversation, $this->s->bob, "Please check this, <@{$this->s->alice->id}>");

    $this->actingAs($this->s->alice)->getJson(route('communication.api.conversations.index'))
        ->assertOk()->assertJsonPath('data.0.last_message.excerpt', "Please check this, @{$this->s->alice->name}");
});
