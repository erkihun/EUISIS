<?php

declare(strict_types=1);

use App\Actions\Communication\CreateGroupAction;
use App\Actions\Communication\SendMessageAction;
use App\Actions\Communication\StartDirectConversationAction;
use App\Enums\AuditEventType;
use App\Models\AuditLog;
use App\Models\Communication\CommunicationAccessRequest;
use App\Notifications\Communication\CommunicationNotification;
use App\Support\Communication\CommunicationRoles;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Support\CommunicationScenario;

/*
 * Break-glass: request with a reason and case reference, approval by someone
 * else for limited hours, read-only and audited reading, revocation and
 * expiry. Nobody reads anything without an approved, unexpired grant of
 * their own, Super Admin included.
 */

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-18 09:00:00'));
    $this->s = CommunicationScenario::build();
    $this->investigator = CommunicationScenario::employeeUser('Iyasu Investigator', $this->s->orgA, $this->s->unitA2, CommunicationRoles::INVESTIGATOR_PERMISSIONS, 'Comm Investigator');
    $this->officer = CommunicationScenario::employeeUser('Penna Privacy', $this->s->orgA, $this->s->unitA2, CommunicationRoles::PRIVACY_OFFICER_PERMISSIONS, 'Comm Privacy Officer');
    $this->direct = app(StartDirectConversationAction::class)->execute($this->s->alice, $this->s->bob);
    app(SendMessageAction::class)->execute($this->direct, $this->s->bob, ['body' => 'Send me the exam answers tonight']);
    $this->other = app(CreateGroupAction::class)->execute($this->s->carol, ['title' => 'Unrelated', 'member_ids' => [$this->s->alice->id]]);
});

afterEach(fn () => Carbon::setTestNow());

function ciRequest(object $test, array $overrides = []): CommunicationAccessRequest
{
    $test->actingAs($test->investigator)->post(route('communication.investigations.store'), [
        'scope_type' => 'EMPLOYEE', 'target_user_id' => $test->s->bob->id, 'hours' => 24, 'case_reference' => 'DISC-2026-041',
        'reason' => 'Disciplinary case on leaking examination material; Bob is the subject of the complaint.', ...$overrides,
    ])->assertRedirect();

    return CommunicationAccessRequest::query()->latest('created_at')->firstOrFail();
}

it('needs a substantial reason and a case reference, and tells approvers without granting anything', function (): void {
    Notification::fake();
    $this->actingAs($this->investigator)->post(route('communication.investigations.store'), [
        'scope_type' => 'EMPLOYEE', 'target_user_id' => $this->s->bob->id, 'hours' => 24, 'case_reference' => 'X', 'reason' => 'curious',
    ])->assertSessionHasErrors('reason');

    $request = ciRequest($this);
    expect($request->status->value)->toBe('REQUESTED')->and($request->requested_hours)->toBe(24);
    Notification::assertSentTo($this->officer, CommunicationNotification::class, fn ($n) => $n->kind === 'access_requested');
    $this->actingAs($this->investigator)->getJson(route('communication.api.investigations.messages', [$request, $this->direct]))->assertNotFound();
    $this->actingAs($this->s->alice)->post(route('communication.investigations.store'), ['scope_type' => 'EMPLOYEE'])->assertForbidden();
});

it('never lets the requester approve, and grants no more hours than asked or allowed', function (): void {
    $request = ciRequest($this, ['hours' => 12]);
    $both = CommunicationScenario::employeeUser('Both Roles', $this->s->orgA, $this->s->unitA1,
        [...CommunicationRoles::INVESTIGATOR_PERMISSIONS, 'communication.break_glass.approve'], 'Comm Both');

    $this->actingAs($this->investigator)->post(route('communication.investigations.decide', $request), ['approve' => true, 'note' => 'Approving my own request'])->assertForbidden();
    $own = ciRequest($this);
    $own->forceFill(['requested_by' => $both->id])->save();
    $this->actingAs($both)->post(route('communication.investigations.decide', $own), ['approve' => true, 'note' => 'Approving my own request'])->assertForbidden();

    $this->actingAs($this->officer)->post(route('communication.investigations.decide', $request), ['approve' => true, 'note' => 'Case file reviewed, scope fits.', 'hours' => 48])
        ->assertSessionHasErrors('hours');
    $this->actingAs($this->officer)->post(route('communication.investigations.decide', $request), ['approve' => true, 'note' => 'Case file reviewed, scope fits.', 'hours' => 6])
        ->assertRedirect();
    expect($request->fresh()->status->value)->toBe('APPROVED')
        ->and($request->fresh()->expires_at->toDateTimeString())->toBe('2026-10-18 15:00:00')
        ->and(AuditLog::query()->where('event_type', AuditEventType::CommunicationAccessDecided->value)->value('reason'))->toBe('Case file reviewed, scope fits.');
});

it('lets the investigator read the covered conversations only, read-only, with every page audited', function (): void {
    $request = ciRequest($this);
    $this->actingAs($this->officer)->post(route('communication.investigations.decide', $request), ['approve' => true, 'note' => 'Case file reviewed, scope fits.']);

    $this->actingAs($this->investigator)->get(route('communication.investigations.show', $request))->assertOk()
        ->assertInertia(fn ($page) => $page->has('conversations', 1)->where('conversations.0.id', $this->direct->id));
    $this->actingAs($this->investigator)->getJson(route('communication.api.investigations.messages', [$request, $this->direct]))->assertOk()
        ->assertJsonPath('data.0.body', 'Send me the exam answers tonight');
    // Carol's group is outside the grant (Bob is not in it), and the normal chat stays closed.
    $this->actingAs($this->investigator)->getJson(route('communication.api.investigations.messages', [$request, $this->other]))->assertNotFound();
    $this->actingAs($this->investigator)->getJson(route('communication.api.conversations.messages', $this->direct))->assertNotFound();
    $this->actingAs($this->investigator)->postJson(route('communication.api.messages.store', $this->direct), ['body' => 'Hi'])->assertNotFound();

    // The approver sees the request, never the content.
    $this->actingAs($this->officer)->get(route('communication.investigations.show', $request))->assertOk()->assertInertia(fn ($page) => $page->where('conversations', null));
    $this->actingAs($this->officer)->getJson(route('communication.api.investigations.messages', [$request, $this->direct]))->assertNotFound();

    expect($request->fresh()->status->value)->toBe('ACTIVE')
        ->and(AuditLog::query()->where('event_type', AuditEventType::CommunicationAccessUsed->value)->count())->toBe(2);
});

it('ends access at expiry or on revocation', function (): void {
    $request = ciRequest($this, ['hours' => 2]);
    $this->actingAs($this->officer)->post(route('communication.investigations.decide', $request), ['approve' => true, 'note' => 'Case file reviewed, scope fits.']);

    Carbon::setTestNow('2026-10-18 11:01:00');
    $this->actingAs($this->investigator)->getJson(route('communication.api.investigations.messages', [$request, $this->direct]))->assertNotFound();
    $this->artisan('communication:expire-investigations')->assertSuccessful();
    expect($request->fresh()->status->value)->toBe('EXPIRED');

    $second = ciRequest($this);
    $this->actingAs($this->officer)->post(route('communication.investigations.decide', $second), ['approve' => true, 'note' => 'Case file reviewed, scope fits.']);
    $this->actingAs($this->officer)->post(route('communication.investigations.revoke', $second), ['reason' => 'Case closed'])->assertRedirect();
    $this->actingAs($this->investigator)->getJson(route('communication.api.investigations.messages', [$second, $this->direct]))->assertNotFound();
    expect($second->fresh()->status->value)->toBe('REVOKED');
});

it('gives Super Admin no way in without the investigator permission and an approved grant', function (): void {
    $root = CommunicationScenario::employeeUser('Root Admin', $this->s->orgA, $this->s->unitA1);
    $root->assignRole(CommunicationScenario::role('Super Admin', ['communication.access']));
    $request = ciRequest($this);
    $this->actingAs($this->officer)->post(route('communication.investigations.decide', $request), ['approve' => true, 'note' => 'Case file reviewed, scope fits.']);

    $this->actingAs($root)->post(route('communication.investigations.store'), ['scope_type' => 'EMPLOYEE', 'target_user_id' => $this->s->bob->id])->assertForbidden();
    $this->actingAs($root)->post(route('communication.investigations.decide', $request), ['approve' => false, 'note' => 'Overriding this one'])->assertNotFound();
    $this->actingAs($root)->getJson(route('communication.api.investigations.messages', [$request, $this->direct]))->assertNotFound();
});
