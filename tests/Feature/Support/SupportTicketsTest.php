<?php

declare(strict_types=1);

use App\Enums\AuditEventType;
use App\Models\AuditLog;
use App\Models\IntelligenceIncident;
use App\Models\SupportTicket;
use App\Models\SupportTicketEvent;
use App\Models\User;
use App\Services\Support\SupportTicketService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * The support help desk (docs/assistant/support-helpdesk.md): references,
 * category routing, safe context, incident-raised priority, reporter
 * isolation, the two grants, events and audit.
 */

function supportUser(array $permissions = []): User
{
    $user = User::factory()->create();
    if ($permissions !== []) {
        $role = Role::findOrCreate('support-'.md5(implode(',', $permissions)), 'web');
        $role->givePermissionTo($permissions);
        $user->assignRole($role);
    }

    return $user;
}

function supportIncident(string $severity): IntelligenceIncident
{
    $now = CarbonImmutable::now();

    return IntelligenceIncident::query()->create([
        'reference' => 'INC-'.$now->format('ym').'-'.str_pad((string) (IntelligenceIncident::query()->count() + 1), 4, '0', STR_PAD_LEFT),
        'title' => 'intelligence.incidents.error_repeat', 'severity' => $severity, 'status' => 'open', 'component' => 'Services/IdCards',
        'fingerprint' => sha1('support-'.$severity), 'rule' => 'error_repeat', 'detected_at' => $now, 'first_seen_at' => $now->subMinutes(10), 'last_seen_at' => $now,
        'evidence' => ['rows' => []],
    ]);
}

beforeEach(function (): void {
    Permission::findOrCreate(SupportTicket::PERMISSION_VIEW_ALL, 'web');
    Permission::findOrCreate(SupportTicket::PERMISSION_MANAGE, 'web');
    config(['support.default_team' => 'Service Desk', 'support.routing' => ['access' => 'Access Team', 'error' => 'Platform Team']]);
});

test('a reporter files a ticket from the page: reference, routed team, created event, audit entry', function (): void {
    $reporter = supportUser();

    $response = $this->actingAs($reporter)->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36')->post(route('support.tickets.store'), [
        'description' => 'I cannot open the employee list; the page shows an error after I click Employees.',
        'category' => 'error',
    ]);

    $ticket = SupportTicket::query()->firstOrFail();
    $response->assertRedirect(route('support.tickets.show', $ticket));
    expect($ticket->reference)->toMatch('/^TKT-\d{4}-0001$/')
        ->and($ticket->reporter_user_id)->toBe($reporter->id)
        ->and($ticket->status)->toBe('open')
        ->and($ticket->priority)->toBe('normal')
        ->and($ticket->assigned_team)->toBe('Platform Team')
        ->and($ticket->title)->toBe('I cannot open the employee list; the page shows an error after I click Employees.')
        ->and($ticket->route_name)->toBe('support.tickets.store')
        ->and($ticket->context['browser'] ?? null)->toBe('Chrome')
        ->and($ticket->events()->count())->toBe(1)
        ->and($ticket->events()->first()->event)->toBe('created')
        ->and(AuditLog::query()->where('event_type', AuditEventType::SupportTicketCreated->value)->where('auditable_id', $ticket->id)->count())->toBe(1);

    $second = app(SupportTicketService::class)->create($reporter, ['description' => 'Second ticket', 'category' => 'how_to']);
    expect($second->reference)->toMatch('/^TKT-\d{4}-0002$/')
        ->and($second->assigned_team)->toBe('Service Desk')
        ->and($second->title)->toBe('Second ticket');
});

test('the context is an allow-list of redacted strings: cookies, tokens, headers, html and bearer values never reach the row', function (): void {
    $reporter = supportUser();
    $service = app(SupportTicketService::class);

    $ticket = $service->create($reporter, [
        'description' => 'Something failed',
        'category' => 'error',
        'route_name' => 'employees.index',
        'path' => '/employees',
        'module' => 'employees',
        'locale' => 'am',
        'error_reference' => 'ERR-ABCD1234EF',
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'context' => [
            'cookie' => 'laravel_session=abc',
            'authorization' => 'Bearer abc123def456ghi789',
            'token' => 'secret-token-value',
            'html' => '<form><input name="password" value="x"></form>',
            'headers' => ['X-Forwarded-For' => '1.2.3.4'],
            'viewport' => 'Bearer abc123def456ghi789 1280x720 <script>alert(1)</script>',
            'browser' => 'Spoofed Browser',
            'route' => str_repeat('r', 500),
        ],
    ]);

    $context = $ticket->refresh()->context;
    expect(array_keys($context))->each->toBeIn(SupportTicketService::CONTEXT_KEYS)
        ->and($context)->not->toHaveKeys(['cookie', 'authorization', 'token', 'html', 'headers'])
        ->and($context['viewport'])->toBe('Bearer [REDACTED] 1280x720 alert(1)')
        ->and($context['browser'])->toBe('Chrome')
        ->and($context['os'])->toBe('Windows')
        ->and($context['device'])->toBe('desktop')
        ->and($context['route'])->toBe('employees.index')
        ->and($context['locale'])->toBe('am')
        ->and($context['error_reference'])->toBe('ERR-ABCD1234EF')
        ->and(mb_strlen($context['path']))->toBeLessThanOrEqual(SupportTicketService::CONTEXT_VALUE_MAX)
        ->and(json_encode($context))->not->toContain('abc123def456ghi789')->not->toContain('secret-token-value')->not->toContain('<');

    $dropped = $service->create($reporter, ['description' => 'Bad error reference', 'category' => 'error', 'error_reference' => 'not-a-reference']);
    expect($dropped->error_reference)->toBeNull();
});

test('priority rises to high only when the linked incident is critical or high', function (): void {
    $reporter = supportUser();
    $service = app(SupportTicketService::class);

    $critical = supportIncident('critical');
    $raised = $service->create($reporter, ['description' => 'Reports fail', 'category' => 'error', 'priority' => 'low', 'incident_id' => $critical->id]);
    expect($raised->priority)->toBe('high')
        ->and($raised->incident_id)->toBe($critical->id)
        ->and($raised->context['incident_reference'])->toBe($critical->reference);

    $low = supportIncident('low');
    $kept = $service->create($reporter, ['description' => 'Minor glitch', 'category' => 'error', 'priority' => 'low', 'incident_id' => $low->id]);
    expect($kept->priority)->toBe('low');

    $unknown = $service->create($reporter, ['description' => 'No such incident', 'category' => 'error', 'priority' => 'normal', 'incident_id' => (string) Str::uuid()]);
    expect($unknown->priority)->toBe('normal')->and($unknown->incident_id)->toBeNull();

    $plain = $service->create($reporter, ['description' => 'Just slow', 'category' => 'performance']);
    expect($plain->priority)->toBe('normal');
});

test('a reporter sees and opens only their own tickets and cannot change them', function (): void {
    $alice = supportUser();
    $bob = supportUser();
    $service = app(SupportTicketService::class);
    $mine = $service->create($alice, ['description' => 'Alice ticket', 'category' => 'access']);
    $theirs = $service->create($bob, ['description' => 'Bob ticket', 'category' => 'data']);

    $this->actingAs($alice)->get(route('support.tickets.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Support/Tickets/Index')
            ->where('can.view_all', false)->where('can.manage', false)
            ->has('tickets.data', 1)->where('tickets.data.0.reference', $mine->reference)->where('tickets.data.0.reporter', null));

    $this->actingAs($alice)->get(route('support.tickets.show', $mine))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Support/Tickets/Show')->where('ticket.reference', $mine->reference)->where('can.manage', false)->has('events', 1));

    $this->actingAs($alice)->get(route('support.tickets.show', $theirs))->assertForbidden();
    $this->actingAs($alice)->patch(route('support.tickets.update', $theirs), ['status' => 'closed'])->assertForbidden();
    $this->actingAs($alice)->patch(route('support.tickets.update', $mine), ['status' => 'closed'])->assertForbidden();
    expect($theirs->refresh()->status)->toBe('open')->and($mine->refresh()->status)->toBe('open');
});

test('view-all sees every ticket and manage transitions with an event and an audit entry', function (): void {
    $alice = supportUser();
    $bob = supportUser();
    $viewer = supportUser([SupportTicket::PERMISSION_VIEW_ALL]);
    $manager = supportUser([SupportTicket::PERMISSION_MANAGE]);
    $service = app(SupportTicketService::class);
    $service->create($alice, ['description' => 'Alice ticket', 'category' => 'access']);
    $ticket = $service->create($bob, ['description' => 'Bob ticket', 'category' => 'data']);

    $this->actingAs($viewer)->get(route('support.tickets.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can.view_all', true)->where('can.manage', false)->has('tickets.data', 2));
    $this->actingAs($viewer)->get(route('support.tickets.show', $ticket))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('ticket.reporter', $bob->name)->where('can.manage', false));
    $this->actingAs($viewer)->patch(route('support.tickets.update', $ticket), ['status' => 'in_progress'])->assertForbidden();

    $this->actingAs($manager)->get(route('support.tickets.show', $ticket))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can.manage', true)->has('assignees', 1)->where('assignees.0.id', $manager->id));

    $this->actingAs($manager)->patch(route('support.tickets.update', $ticket), ['status' => 'in_progress', 'assigned_user_id' => $manager->id, 'note' => 'Looking into it'])
        ->assertRedirect()->assertSessionHas('flash.type', 'success');

    $ticket->refresh();
    expect($ticket->status)->toBe('in_progress')
        ->and($ticket->assigned_user_id)->toBe($manager->id)
        ->and($ticket->events()->pluck('event')->all())->toBe(['created', 'assigned', 'status_changed'])
        ->and($ticket->events()->where('event', 'status_changed')->first()->note)->toBe('Looking into it')
        ->and(AuditLog::query()->where('event_type', AuditEventType::SupportTicketUpdated->value)->where('auditable_id', $ticket->id)->count())->toBe(2);

    $this->actingAs($manager)->patch(route('support.tickets.update', $ticket), ['status' => 'resolved'])->assertRedirect();
    expect($ticket->refresh()->resolved_at)->not->toBeNull()->and($ticket->status)->toBe('resolved');

    $this->actingAs($manager)->patch(route('support.tickets.update', $ticket), ['note' => 'Follow-up only'])->assertRedirect();
    expect(SupportTicketEvent::query()->where('ticket_id', $ticket->id)->where('event', 'note')->count())->toBe(1);

    // The index for the manager shows only active tickets by default and all on request.
    $this->actingAs($manager)->get(route('support.tickets.index'))->assertInertia(fn (Assert $page) => $page->has('tickets.data', 1));
    $this->actingAs($manager)->get(route('support.tickets.index', ['status' => 'all']))->assertInertia(fn (Assert $page) => $page->has('tickets.data', 2));
});

test('invalid categories, statuses and missing descriptions are rejected', function (): void {
    $reporter = supportUser();
    $manager = supportUser([SupportTicket::PERMISSION_MANAGE]);

    $this->actingAs($reporter)->from(route('support.tickets.index'))->post(route('support.tickets.store'), ['description' => 'x', 'category' => 'nonsense'])
        ->assertSessionHasErrors(['category']);
    $this->actingAs($reporter)->from(route('support.tickets.index'))->post(route('support.tickets.store'), ['description' => '', 'category' => 'error'])
        ->assertSessionHasErrors(['description']);
    $this->actingAs($reporter)->postJson(route('support.tickets.store'), ['description' => 'x', 'category' => 'error', 'priority' => 'urgent'])
        ->assertStatus(422);
    expect(SupportTicket::query()->count())->toBe(0);

    $ticket = app(SupportTicketService::class)->create($reporter, ['description' => 'Valid', 'category' => 'other']);
    $this->actingAs($manager)->patchJson(route('support.tickets.update', $ticket), ['status' => 'archived'])->assertStatus(422);
    $this->actingAs($reporter)->getJson(route('support.tickets.index', ['category' => 'bogus']))->assertStatus(422);
    expect($ticket->refresh()->status)->toBe('open');
});
