<?php

declare(strict_types=1);

use App\Enums\AuditEventType;
use App\Models\AuditLog;
use App\Models\SituationRoomAction;
use App\Services\SituationRoom\SituationPermissions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SituationRoomScenario;

/*
 * The category views' links into the one Action Register, exports (same scope
 * as the screen, no names, audited) and the wall (aggregates only).
 */

beforeEach(function (): void {
    app()->setLocale('en');
    $this->s = SituationRoomScenario::build();
});

it('records an action from a category panel in the one Action Register', function (): void {
    $manager = $this->s->viewer([SituationPermissions::ACTIONS_MANAGE, SituationPermissions::PUBLIC_VOICE], 'subcity_a');

    $this->actingAs($manager)->post(route('situation-room.actions.store'), [
        'organization_id' => $this->s->org['woreda_a1']->id,
        'source_type' => 'panel',
        'source_reference' => 'complaints:complaints_by_organization',
        'title' => 'Review the front desk',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $action = SituationRoomAction::query()->sole();
    expect($action->source_type)->toBe('panel')
        ->and($action->source_reference)->toBe('complaints:complaints_by_organization');

    // A sibling's institution is refused.
    $this->actingAs($manager)->post(route('situation-room.actions.store'), ['organization_id' => $this->s->org['woreda_b1']->id, 'title' => 'x'])->assertForbidden();
});

it('shows open actions of the scope on the Executive Overview, never a sibling\'s', function (): void {
    $creator = $this->s->viewer([], 'bureau');
    SituationRoomAction::query()->create(['organization_id' => $this->s->org['woreda_a1']->id, 'title' => 'Inside A', 'status' => 'open', 'priority' => 'high', 'created_by' => $creator->id]);
    SituationRoomAction::query()->create(['organization_id' => $this->s->org['woreda_b1']->id, 'title' => 'Inside B', 'status' => 'open', 'priority' => 'normal', 'created_by' => $creator->id]);
    $viewer = $this->s->viewer([], 'subcity_a');

    $result = null;
    $this->actingAs($viewer)->get(route('situation-room.index'))->assertOk()
        ->assertInertia(function (Assert $page) use (&$result): void {
            $page->loadDeferredProps(function (Assert $reload) use (&$result): void {
                $result = $reload->toArray()['props']['result'];
            });
        });
    $json = json_encode($result);

    expect($json)->toContain('Inside A')->not->toContain('Inside B');
});

it('exports a category in the viewer\'s scope, without names, and audits it', function (string $format): void {
    $viewer = $this->s->viewer([SituationPermissions::REPORTS, SituationPermissions::PUBLIC_VOICE, SituationPermissions::EMPLOYEE_BREAKDOWN, SituationPermissions::EMPLOYEE_TARGET], 'subcity_a');

    $response = $this->actingAs($viewer)->get(route('situation-room.reports.export', ['category' => 'complaints', 'format' => $format, 'locale' => 'en', 'organization_id' => $this->s->org['woreda_a1']->id]));
    $response->assertOk();

    if ($format === 'csv') {
        $content = $response->streamedContent() ?: $response->getContent();
        expect($content)->toContain('Complaints received')->not->toContain('Person ')->not->toContain('Private Submitter')->not->toContain('Subcity B');
    }
    expect(AuditLog::query()->where('event_type', AuditEventType::SituationRoomExported->value)->count())->toBe(1);
})->with(['csv', 'xlsx', 'pdf']);

it('refuses exports without the export grant or outside the scope', function (): void {
    $noReports = $this->s->viewer([SituationPermissions::PUBLIC_VOICE], 'subcity_a');
    $reports = $this->s->viewer([SituationPermissions::REPORTS, SituationPermissions::PUBLIC_VOICE], 'subcity_a');

    $this->actingAs($noReports)->get(route('situation-room.reports.export', ['category' => 'complaints', 'format' => 'csv']))->assertForbidden();
    $this->actingAs($reports)->get(route('situation-room.reports.export', ['category' => 'workforce', 'format' => 'csv']))->assertForbidden();
    $this->actingAs($reports)->get(route('situation-room.reports.export', ['category' => 'complaints', 'format' => 'csv', 'organization_id' => $this->s->org['woreda_b1']->id]))->assertForbidden();
});

it('puts only aggregates on the wall, even for a viewer allowed to see names', function (): void {
    $wall = $this->s->viewer([SituationPermissions::WALL, SituationPermissions::PUBLIC_VOICE, SituationPermissions::WORKFORCE, SituationPermissions::EMPLOYEE_BREAKDOWN, SituationPermissions::EMPLOYEE_TARGET], 'bureau');
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

it('names employees only with both grants, and audits it', function (): void {
    $this->s->submission($this->s->org['woreda_a1'], 'complaint', ['target_type' => 'employee', 'source_type' => 'employee_card_qr', 'organization_target_id' => null, 'employee_id' => $this->s->people['woreda_a1'][0]->id]);
    $url = route('situation-room.category', ['category' => 'complaints', 'organization_id' => $this->s->org['woreda_a1']->id]);
    $panelType = function ($user) use ($url): string {
        $type = '';
        $this->actingAs($user)->get($url)->assertInertia(function (Assert $page) use (&$type): void {
            $page->loadDeferredProps(function (Assert $reload) use (&$type): void {
                $type = collect($reload->toArray()['props']['result']['payload']['panels'])->firstWhere('key', 'complaints_employees')['type'];
            });
        });

        return $type;
    };

    expect($panelType($this->s->viewer([SituationPermissions::PUBLIC_VOICE, SituationPermissions::EMPLOYEE_BREAKDOWN], 'subcity_a')))->toBe('notice')
        ->and(AuditLog::query()->where('event_type', AuditEventType::SituationRoomEmployeeBreakdownViewed->value)->count())->toBe(0)
        ->and($panelType($this->s->viewer([SituationPermissions::PUBLIC_VOICE, SituationPermissions::EMPLOYEE_BREAKDOWN, SituationPermissions::EMPLOYEE_TARGET], 'subcity_a')))->toBe('table')
        ->and(AuditLog::query()->where('event_type', AuditEventType::SituationRoomEmployeeBreakdownViewed->value)->count())->toBe(1);
});

it('opens Community Voice by Institution only with the public-voice grant', function (): void {
    $without = $this->s->viewer([], 'bureau');
    $with = $this->s->viewer([SituationPermissions::PUBLIC_VOICE], 'bureau');

    $this->actingAs($without)->get(route('situation-room.community-voice'))->assertForbidden();
    $this->actingAs($with)->get(route('situation-room.community-voice'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('SituationRoom/CommunityVoice'));
});

it('keeps an action\'s description as sanitized formatted text', function (): void {
    $manager = $this->s->viewer([SituationPermissions::ACTIONS_MANAGE, SituationPermissions::PUBLIC_VOICE], 'subcity_a');
    $store = fn (string $text) => $this->actingAs($manager)->from('/')->post(route('situation-room.actions.store'), [
        'organization_id' => $this->s->org['woreda_a1']->id, 'title' => 'Front desk', 'action_text' => $text,
    ]);

    $store('<p>Call the <strong>woreda</strong> office<script>alert(1)</script></p><ul><li onmouseover="x()">today</li></ul>')->assertSessionHasNoErrors();
    expect(SituationRoomAction::query()->latest('created_at')->first()->action_text)->toBe('<p>Call the <strong>woreda</strong> office</p><ul><li>today</li></ul>');

    // An emptied editor is no description at all; the limit counts what a reader sees.
    $store('<p><br></p>')->assertSessionHasNoErrors();
    expect(SituationRoomAction::query()->whereNull('action_text')->count())->toBe(1);
    $store('<p>'.str_repeat('a', 5001).'</p>')->assertSessionHasErrors('action_text');
});
