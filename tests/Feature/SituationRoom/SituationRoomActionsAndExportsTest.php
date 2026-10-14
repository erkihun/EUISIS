<?php

declare(strict_types=1);

use App\Enums\AuditEventType;
use App\Models\AuditLog;
use App\Models\SituationAction;
use App\Services\SituationRoom\SituationPermissions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SituationRoomScenario;

/*
 * Executive Action Register: scoped, audited, never touching source records.
 * Exports: same scope as the screen, no names, audited. Wall: aggregates only.
 */

beforeEach(function (): void {
    app()->setLocale('en');
    $this->s = SituationRoomScenario::build();
    $this->manager = $this->s->viewer([SituationPermissions::ACTIONS_VIEW, SituationPermissions::ACTIONS_MANAGE], 'subcity_a');
});

function srAction(array $overrides = []): array
{
    return $overrides + ['category' => 'complaints', 'panel_key' => 'complaints_by_organization', 'title' => 'Review the front desk', 'priority' => 'high', 'due_date' => now()->addWeek()->toDateString()];
}

it('records an action for an organization in scope, with an audit entry', function (): void {
    $this->actingAs($this->manager)->post(route('situation-room.actions.store'), srAction(['organization_id' => $this->s->org['woreda_a1']->id]))
        ->assertRedirect(route('situation-room.actions.index'))->assertSessionHasNoErrors();

    $action = SituationAction::query()->sole();
    expect($action->reference_number)->toStartWith('SRA-')
        ->and($action->status->value)->toBe('open')
        ->and(AuditLog::query()->where('event_type', AuditEventType::SituationActionCreated->value)->where('auditable_id', $action->id)->exists())->toBeTrue();
});

it('refuses an action for an organization outside the scope', function (): void {
    $this->actingAs($this->manager)->post(route('situation-room.actions.store'), srAction(['organization_id' => $this->s->org['woreda_b1']->id]))
        ->assertSessionHasErrors('organization_id');

    expect(SituationAction::query()->count())->toBe(0);
});

it('hides another scope\'s action and refuses to edit it (IDOR)', function (): void {
    $foreign = $this->s->viewer([SituationPermissions::ACTIONS_VIEW, SituationPermissions::ACTIONS_MANAGE], 'subcity_b');
    $this->actingAs($foreign)->post(route('situation-room.actions.store'), srAction(['organization_id' => $this->s->org['woreda_b1']->id]))->assertSessionHasNoErrors();
    $action = SituationAction::query()->sole();

    $this->actingAs($this->manager)->get(route('situation-room.actions.edit', $action))->assertNotFound();
    $this->actingAs($this->manager)->put(route('situation-room.actions.update', $action), srAction(['status' => 'done', 'outcome_note' => 'x']))->assertForbidden();
    $this->actingAs($this->manager)->get(route('situation-room.actions.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('actions.total', 0));

    expect($action->fresh()->status->value)->toBe('open');
});

it('needs an outcome to close an action, stamps completion and audits the change', function (): void {
    $this->actingAs($this->manager)->post(route('situation-room.actions.store'), srAction(['organization_id' => $this->s->org['woreda_a2']->id]));
    $action = SituationAction::query()->sole();

    $this->actingAs($this->manager)->put(route('situation-room.actions.update', $action), srAction(['status' => 'done']))->assertSessionHasErrors('outcome_note');
    $this->actingAs($this->manager)->put(route('situation-room.actions.update', $action), srAction(['status' => 'done', 'outcome_note' => 'Desk staffed']))->assertSessionHasNoErrors();
    $this->actingAs($this->manager)->put(route('situation-room.actions.update', $action), srAction(['status' => 'done', 'outcome_note' => 'Desk staffed', 'organization_id' => $this->s->org['woreda_b1']->id]))
        ->assertSessionHasErrors('organization_id');

    $action->refresh();
    expect($action->status->value)->toBe('done')
        ->and($action->completed_at)->not->toBeNull()
        ->and($action->organization_id)->toBe($this->s->org['woreda_a2']->id)
        ->and(AuditLog::query()->where('event_type', AuditEventType::SituationActionUpdated->value)->count())->toBe(1);
});

it('lets a viewer without the manage grant read but not write', function (): void {
    $reader = $this->s->viewer([SituationPermissions::ACTIONS_VIEW], 'subcity_a');

    $this->actingAs($reader)->get(route('situation-room.actions.index'))->assertOk();
    $this->actingAs($reader)->get(route('situation-room.actions.create'))->assertForbidden();
    $this->actingAs($reader)->post(route('situation-room.actions.store'), srAction(['organization_id' => $this->s->org['woreda_a1']->id]))->assertForbidden();
});

it('exports a category in the viewer\'s scope, without names, and audits it', function (string $format): void {
    $viewer = $this->s->viewer([SituationPermissions::REPORTS, SituationPermissions::PUBLIC_VOICE, SituationPermissions::EMPLOYEE_BREAKDOWN], 'subcity_a');

    $response = $this->actingAs($viewer)->get(route('situation-room.reports.export', ['category' => 'complaints', 'format' => $format, 'locale' => 'en', 'organization_id' => $this->s->org['woreda_a1']->id]));
    $response->assertOk();

    if ($format !== 'pdf') {
        $content = $response->streamedContent() ?: $response->getContent();
        if ($format === 'csv') {
            expect($content)->toContain('Complaints received')->not->toContain('Person ')->not->toContain('Private Submitter')->not->toContain('Subcity B');
        }
    }
    expect(AuditLog::query()->where('event_type', AuditEventType::SituationRoomExported->value)->count())->toBe(1);
})->with(['csv', 'xlsx', 'pdf']);

it('refuses exports without the reports grant or outside the scope', function (): void {
    $noReports = $this->s->viewer([SituationPermissions::PUBLIC_VOICE], 'subcity_a');
    $reports = $this->s->viewer([SituationPermissions::REPORTS, SituationPermissions::PUBLIC_VOICE], 'subcity_a');

    $this->actingAs($noReports)->get(route('situation-room.reports.export', ['category' => 'complaints', 'format' => 'csv']))->assertForbidden();
    $this->actingAs($reports)->get(route('situation-room.reports.export', ['category' => 'workforce', 'format' => 'csv']))->assertForbidden();
    $this->actingAs($reports)->get(route('situation-room.reports.export', ['category' => 'complaints', 'format' => 'csv', 'organization_id' => $this->s->org['woreda_b1']->id]))->assertForbidden();
});

it('puts only aggregates on the wall, even for a viewer allowed to see names', function (): void {
    $wall = $this->s->viewer([SituationPermissions::WALL, SituationPermissions::PUBLIC_VOICE, SituationPermissions::WORKFORCE, SituationPermissions::EMPLOYEE_BREAKDOWN], 'bureau');
    $result = null;
    $this->actingAs($wall)->get(route('situation-room.wall', ['organization_id' => $this->s->org['woreda_a1']->id]))->assertOk()
        ->assertInertia(function (Assert $page) use (&$result): void {
            $page->component('SituationRoom/Wall')->loadDeferredProps(function (Assert $reload) use (&$result): void {
                $result = $reload->toArray()['props']['result'];
            });
        });

    $types = collect($result['payload']['screens'])->flatMap(fn (array $s) => array_column($s['panels'], 'type'))->unique()->all();
    expect($types)->not->toContain('table')
        ->and(json_encode($result))->not->toContain('Person ')->not->toContain('Private Submitter')->not->toContain('_employees');
});

it('records an audit entry when an employee-level breakdown is shown', function (): void {
    $viewer = $this->s->viewer([SituationPermissions::PUBLIC_VOICE, SituationPermissions::EMPLOYEE_BREAKDOWN], 'subcity_a');
    $this->s->submission($this->s->org['woreda_a1'], 'complaint', ['target_type' => 'employee', 'source_type' => 'employee_card_qr', 'organization_target_id' => null, 'employee_id' => $this->s->people['woreda_a1'][0]->id]);

    $this->actingAs($viewer)->get(route('situation-room.category', ['category' => 'complaints', 'organization_id' => $this->s->org['woreda_a1']->id]))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload->where('result.payload.panels', fn ($panels) => collect($panels)->firstWhere('key', 'complaints_employees')['type'] === 'table')));

    expect(AuditLog::query()->where('event_type', AuditEventType::SituationRoomEmployeeBreakdownViewed->value)->count())->toBe(1);
});
