<?php

declare(strict_types=1);

use App\Actions\IdCards\RevokeCardAction;
use App\Models\Integration\IntegrationEvent;
use App\Models\User;
use App\Services\Integration\Events\FeedCursor;
use Modules\Transfer\Application\Actions\CompleteTransferAction;
use Modules\Transfer\Contracts\Data\TransferAnnouncementStatus;
use Modules\Transfer\Contracts\Data\TransferApplicationStatus;
use Modules\Transfer\Domain\Models\TransferAnnouncement;
use Modules\Transfer\Domain\Models\TransferApplication;
use Modules\Transfer\Domain\Models\TransferSetting;
use Spatie\Permission\Models\Permission;
use Tests\Support\IntegrationScenario;

/**
 * Change feed: authentication, scope and organization filtering in SQL,
 * opaque cursors, keyset paging under concurrent inserts, reconciliation,
 * and the transfer cross-scope edge case.
 */
beforeEach(function (): void {
    $this->s = IntegrationScenario::build();
    IntegrationScenario::publish();
});

/** @return list<array<string, mixed>> every event reachable from $cursor */
function drainFeed($test, string $token, ?string $cursor = null, int $limit = 5, array $query = []): array
{
    $events = [];
    for ($i = 0; $i < 200; $i++) {
        app('auth')->forgetGuards();
        $response = $test->withToken($token)->getJson('/api/v1/changes?'.http_build_query(array_filter(['cursor' => $cursor, 'limit' => $limit, ...$query])))->assertOk();
        $events = [...$events, ...$response->json('data')];
        $cursor = $response->json('next_cursor');
        if (! $response->json('has_more')) {
            break;
        }
    }

    return ['events' => $events, 'cursor' => $cursor];
}

it('refuses the feed without authentication, without changes.read, to a person, and when disabled', function (): void {
    $this->getJson('/api/v1/changes')->assertUnauthorized();

    [, $noFeed] = IntegrationScenario::application(['employees.basic_read']);
    $this->withToken($noFeed)->getJson('/api/v1/changes')->assertForbidden();

    $person = User::factory()->create()->createToken('p', ['changes.read'])->plainTextToken;
    $this->app['auth']->forgetGuards();
    $this->withToken($person)->getJson('/api/v1/changes')->assertForbidden()->assertJsonPath('error_code', 'application_required');

    config(['integration.change_feed.enabled' => false]);
    [, $token] = IntegrationScenario::application(['changes.read', 'employees.basic_read']);
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/changes')->assertNotFound()->assertJsonPath('error_code', 'change_feed_disabled');
});

it('pages the whole feed in order with no duplicates and stable cursors', function (): void {
    [, $token] = IntegrationScenario::application(['changes.read', 'organizations.read', 'organization_units.read', 'positions.read', 'employees.basic_read', 'employee_assignments.read', 'id_cards.verify']);

    $all = drainFeed($this, $token, limit: 3)['events'];
    $ids = collect($all)->pluck('event_id');

    expect($ids->unique()->count())->toBe($ids->count())
        ->and($ids->count())->toBe(IntegrationEvent::query()->whereNotNull('feed_position')->count())
        ->and($all[0])->toHaveKeys(['event_id', 'event_type', 'event_version', 'aggregate_type', 'aggregate_uuid', 'organization_uuid', 'sequence', 'change_kind', 'occurred_at', 'effective_at', 'correlation_id', 'data'])
        ->and($all[0])->not->toHaveKey('metadata');
});

it('validates the limit and refuses a tampered or borrowed cursor', function (): void {
    [$application, $token] = IntegrationScenario::application(['changes.read', 'employees.basic_read']);
    [$other] = IntegrationScenario::application(['changes.read', 'employees.basic_read']);

    $this->withToken($token)->getJson('/api/v1/changes?limit=0')->assertUnprocessable();
    $this->withToken($token)->getJson('/api/v1/changes?limit=100000')->assertUnprocessable();

    $cursor = FeedCursor::encode(3, $application->id);
    $tampered = substr($cursor, 0, -2).(str_ends_with($cursor, 'aa') ? 'bb' : 'aa');
    $this->withToken($token)->getJson('/api/v1/changes?cursor='.$tampered)->assertStatus(400)->assertJsonPath('error_code', 'invalid_cursor');
    $this->withToken($token)->getJson('/api/v1/changes?cursor='.FeedCursor::encode(3, $other->id))->assertStatus(400);
    $this->withToken($token)->getJson('/api/v1/changes?cursor='.$cursor)->assertOk();
});

it('shows only event types the application has scopes for, and filters by type', function (): void {
    [, $token] = IntegrationScenario::application(['changes.read', 'organizations.read']);

    $types = collect(drainFeed($this, $token)['events'])->pluck('event_type')->unique();
    expect($types->every(fn ($type) => str_starts_with($type, 'organization.')))->toBeTrue()->and($types)->not->toBeEmpty();

    [, $both] = IntegrationScenario::application(['changes.read', 'organizations.read', 'employees.basic_read']);
    $filtered = collect(drainFeed($this, $both, query: ['event_types' => 'employee.created'])['events'])->pluck('event_type')->unique()->values()->all();
    expect($filtered)->toBe(['employee.created']);
});

it('never shows a scoped partner another organization\'s events', function (): void {
    [$application, $token] = IntegrationScenario::application(['changes.read', 'employees.basic_read', 'organizations.read'], ['organization_access' => 'scoped']);
    $application->organizationScopes()->create(['organization_id' => $this->s->bureau->id, 'scope_type' => 'subtree']);

    $this->s->otherEmployee->update(['first_name' => 'Other']);
    $this->s->woredaEmployee->update(['first_name' => 'Inside']);
    $this->s->other->update(['name_en' => 'Other Renamed']);
    IntegrationScenario::publish();

    $events = collect(drainFeed($this, $token)['events']);
    $orgs = $events->pluck('organization_uuid')->unique()->values();

    expect($orgs->diff([$this->s->bureau->id, $this->s->woreda->id])->all())->toBe([])
        ->and($events->pluck('aggregate_uuid'))->toContain($this->s->woredaEmployee->id)
        ->and($events->pluck('aggregate_uuid'))->not->toContain($this->s->otherEmployee->id)
        ->and($events->pluck('aggregate_uuid'))->not->toContain($this->s->other->id);

    [, $unreviewed] = IntegrationScenario::application(['changes.read', 'employees.basic_read'], ['organization_access' => 'unreviewed']);
    expect(drainFeed($this, $unreviewed)['events'])->toBe([]);
});

it('misses and duplicates nothing when events are published while a partner is paging', function (): void {
    [, $token] = IntegrationScenario::application(['changes.read', 'organizations.read']);

    $first = drainFeed($this, $token);
    $seen = collect($first['events'])->pluck('event_id');

    $this->s->bureau->update(['name_en' => 'Bureau 2']);
    $this->s->woreda->update(['name_en' => 'Woreda 2']);
    IntegrationScenario::publish();
    $this->s->other->update(['name_en' => 'Other 2']);
    IntegrationScenario::publish();

    $second = collect(drainFeed($this, $token, $first['cursor'], 1)['events']);
    expect($second->pluck('event_id')->intersect($seen)->all())->toBe([])
        ->and($second->where('event_type', 'organization.updated')->pluck('data.name_en')->sort()->values()->all())->toBe(['Bureau 2', 'Other 2', 'Woreda 2']);
});

it('lets a partner reconcile a missed event through sequence and the feed', function (): void {
    [, $token] = IntegrationScenario::application(['changes.read', 'id_cards.verify']);
    $cursor = drainFeed($this, $token)['cursor'];

    app(RevokeCardAction::class)->execute($this->s->woredaCard, IntegrationScenario::admin(), 'lost');
    IntegrationScenario::publish();

    // The webhook was missed; reconcile shows the partner is behind.
    $this->app['auth']->forgetGuards();
    $reconcile = $this->withToken($token)->postJson('/api/v1/changes/reconcile', ['aggregate_type' => 'id_card', 'uuids' => [$this->s->woredaCard->id, '00000000-0000-0000-0000-000000000001']])->assertOk();
    expect($reconcile->json('data.0.found'))->toBeTrue()
        ->and($reconcile->json('data.0.last_event_type'))->toBe('id_card.revoked')
        ->and($reconcile->json('data.1.found'))->toBeFalse();

    $missed = collect(drainFeed($this, $token, $cursor)['events']);
    expect($missed->pluck('event_type'))->toContain('id_card.revoked')
        ->and($missed->firstWhere('event_type', 'id_card.revoked')['data']['card_uuid'])->toBe($this->s->woredaCard->public_card_uuid);

    // The verification API agrees: the card is no longer valid.
    [, $verify] = IntegrationScenario::application(['id_cards.verify']);
    $this->app['auth']->forgetGuards();
    $this->withToken($verify)->getJson('/api/v1/id-cards/verify/'.$this->s->woredaCard->public_card_uuid)->assertOk()->assertJsonPath('valid', false);
});

it('offers a watermark for bootstrap and refuses oversized reconciliation', function (): void {
    [$application, $token] = IntegrationScenario::application(['changes.read', 'employees.basic_read']);

    $watermark = $this->withToken($token)->getJson('/api/v1/changes/watermark')->assertOk()->json('cursor');
    expect(FeedCursor::decode($watermark, $application->id))->toBe(IntegrationEvent::query()->max('feed_position'));

    $this->withToken($token)->postJson('/api/v1/changes/reconcile', ['aggregate_type' => 'employee', 'uuids' => array_fill(0, 101, 'x')])->assertUnprocessable();
});

it('publishes one transfer event, keeps identities, and minimises it for a source-only partner', function (): void {
    foreach (['transfers.complete'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $employee = $this->s->otherEmployee;
    $card = IntegrationScenario::card($employee, 'EVT-CARD-O');
    $toPosition = IntegrationScenario::position($this->s->woreda, 'EVT-DEST');
    $oldAssignmentId = $employee->current_assignment_id;

    $announcement = TransferAnnouncement::query()->create([
        'organization_id' => $this->s->woreda->id, 'position_id' => $toPosition->id, 'number_of_vacancies' => 1,
        'opening_date' => now()->subDay()->toDateString(), 'closing_date' => now()->addMonth()->toDateString(),
        'status' => TransferAnnouncementStatus::Published, 'created_by' => IntegrationScenario::admin()->id,
    ]);
    $application = TransferApplication::query()->create([
        'announcement_id' => $announcement->id, 'employee_id' => $employee->id, 'current_assignment_id' => $oldAssignmentId,
        'releasing_organization_id' => $this->s->other->id, 'receiving_organization_id' => $this->s->woreda->id,
        'status' => TransferApplicationStatus::Approved, 'submitted_at' => now(),
    ]);
    TransferSetting::current()->update(['releasing_consent_required' => false, 'receiving_consent_required' => false, 'final_approval_required' => false, 'card_reprint_policy' => 'no_reprint', 'service_recalculation_policy' => 'no_recalculation']);

    $since = (int) IntegrationEvent::query()->max('id');
    app(CompleteTransferAction::class)->execute($application->fresh(), IntegrationScenario::admin());
    IntegrationScenario::publish();

    $new = IntegrationEvent::query()->where('id', '>', $since)->get();
    expect($new->where('event_type', 'employee.transferred'))->toHaveCount(1)
        ->and($new->where('event_type', 'employee.assignment_changed'))->toHaveCount(0)
        ->and($new->where('event_type', 'assignment.closed')->first()?->aggregate_uuid)->toBe($oldAssignmentId)
        ->and($new->where('event_type', 'assignment.created'))->toHaveCount(1)
        ->and($employee->fresh()->id)->toBe($employee->id)
        ->and($card->fresh()->public_card_uuid)->toBe($card->public_card_uuid);

    $transfer = $new->firstWhere('event_type', 'employee.transferred');
    expect($transfer->organization_id)->toBe($this->s->woreda->id)->and($transfer->related_organization_id)->toBe($this->s->other->id);

    // Source-only partner: told the employee left, not where to.
    [$source, $sourceToken] = IntegrationScenario::application(['changes.read', 'employee_assignments.read'], ['organization_access' => 'scoped']);
    $source->organizationScopes()->create(['organization_id' => $this->s->other->id, 'scope_type' => 'self']);
    $seenBySource = collect(drainFeed($this, $sourceToken)['events'])->firstWhere('event_type', 'employee.transferred');
    expect($seenBySource['data']['visibility'])->toBe('source_only')
        ->and($seenBySource['data']['organization_uuid'])->toBeNull()
        ->and($seenBySource['data']['position_uuid'])->toBeNull()
        ->and($seenBySource['data']['assignment_uuid'])->toBeNull()
        ->and($seenBySource['organization_uuid'])->toBe($this->s->other->id)
        ->and(json_encode($seenBySource))->not->toContain($this->s->woreda->id);

    // Destination partner (bureau subtree): full event.
    [$dest, $destToken] = IntegrationScenario::application(['changes.read', 'employee_assignments.read'], ['organization_access' => 'scoped']);
    $dest->organizationScopes()->create(['organization_id' => $this->s->bureau->id, 'scope_type' => 'subtree']);
    $seenByDest = collect(drainFeed($this, $destToken)['events'])->firstWhere('event_type', 'employee.transferred');
    expect($seenByDest['data']['visibility'])->toBe('full')
        ->and($seenByDest['data']['organization_uuid'])->toBe($this->s->woreda->id)
        ->and($seenByDest['data']['previous_organization_uuid'])->toBe($this->s->other->id)
        ->and($seenByDest['effective_at'])->not->toBeNull();
});
