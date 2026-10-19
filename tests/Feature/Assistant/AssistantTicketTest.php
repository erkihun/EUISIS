<?php

declare(strict_types=1);

use App\Enums\AuditEventType;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\AuditLog;
use App\Models\SupportTicket;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\SystemSettings\SystemSettingsService;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;

/*
 * Ticket creation from the assistant (docs/assistant/support-helpdesk.md):
 * explicit, typed, audited, with safe context only, confirmed in the
 * conversation, and off when support is switched off.
 */

function ticketUser(): User
{
    return User::factory()->create();
}

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    Cache::flush();
    foreach ([SupportTicket::PERMISSION_VIEW_ALL, SupportTicket::PERMISSION_MANAGE] as $name) {
        Permission::findOrCreate($name, 'web');
    }
});

test('an explicit ticket request creates one ticket with safe context, a confirmation message and an audit entry', function (): void {
    $user = ticketUser();
    $first = $this->actingAs($user)->postJson(route('assistant.messages.store'), ['message' => 'Export is not working', 'page' => ['route' => 'employees.index', 'path' => '/employees']])->assertOk();
    $conversationId = $first->json('conversation_id');
    expect(collect($first->json('reply.blocks'))->firstWhere('type', 'ticket_offer'))->not->toBeNull()->and(SupportTicket::query()->count())->toBe(0);

    $response = $this->actingAs($user)->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0'])
        ->postJson(route('assistant.tickets.store'), [
            'conversation_id' => $conversationId, 'description' => 'The PDF export button does nothing. Token: Bearer abcdefghijklmnop123456',
            'category' => 'error', 'priority' => 'normal', 'error_reference' => 'ERR-ABCDEFGH12',
            'page' => ['route' => 'employees.index', 'path' => '/employees', 'cookie' => 'laravel_session=secret', 'authorization' => 'Bearer x'],
        ])->assertCreated();

    $ticket = SupportTicket::query()->firstOrFail();
    expect($response->json('ticket.reference'))->toBe($ticket->reference)->and($response->json('ticket.href'))->toBe('/support/tickets/'.$ticket->id)
        ->and($response->json('reply.blocks.0.type'))->toBe('ticket')
        ->and($ticket->reporter_user_id)->toBe($user->id)->and($ticket->module)->toBe('employees')->and($ticket->error_reference)->toBe('ERR-ABCDEFGH12')
        ->and($ticket->conversation_id)->toBe($conversationId)->and($ticket->priority)->toBe('normal')
        ->and(array_keys($ticket->context))->each->toBeIn(['route', 'path', 'module', 'locale', 'browser', 'os', 'device', 'error_reference', 'incident_reference', 'timestamp', 'viewport'])
        ->and(json_encode($ticket->context))->not->toContain('secret')->not->toContain('Bearer')
        ->and($ticket->description)->not->toContain('abcdefghijklmnop123456')
        ->and(AssistantMessage::query()->where('conversation_id', $conversationId)->where('role', 'assistant')->count())->toBe(2)
        ->and(AuditLog::query()->where('event_type', AuditEventType::SupportTicketCreated->value)->count())->toBe(1);

    // The reporter opens their own ticket; another person cannot.
    $this->actingAs($user)->get('/support/tickets/'.$ticket->id)->assertOk();
    $this->actingAs(ticketUser())->get('/support/tickets/'.$ticket->id)->assertForbidden();
    $this->actingAs(ticketUser())->postJson(route('assistant.tickets.store'), ['conversation_id' => $conversationId, 'description' => 'Hijack attempt here', 'category' => 'other', 'page' => []])->assertNotFound();
    expect(AssistantConversation::query()->count())->toBe(1);
});

test('ticket creation is refused when support is switched off and validated otherwise', function (): void {
    $user = ticketUser();
    $this->actingAs($user)->postJson(route('assistant.tickets.store'), ['description' => 'x', 'category' => 'bogus', 'page' => []])->assertStatus(422);

    SystemSetting::query()->updateOrCreate(['group' => 'assistant', 'key' => 'support_enabled'], ['value' => '0', 'type' => 'boolean', 'label_en' => 'support', 'label_am' => 'support']);
    app(SystemSettingsService::class)->clearCache();
    $this->actingAs($user)->postJson(route('assistant.tickets.store'), ['description' => 'Something broke badly', 'category' => 'error', 'page' => []])->assertForbidden()->assertJsonPath('error', 'support_disabled');
    $reply = $this->actingAs($user)->postJson(route('assistant.messages.store'), ['message' => 'Something is not working', 'page' => []])->assertOk()->json('reply');
    expect(collect($reply['blocks'])->firstWhere('type', 'ticket_offer'))->toBeNull()
        ->and($this->actingAs($user)->getJson(route('assistant.bootstrap'))->json('quick_actions.*.key'))->not->toContain('create_ticket');
});
