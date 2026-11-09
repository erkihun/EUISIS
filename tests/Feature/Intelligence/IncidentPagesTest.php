<?php

declare(strict_types=1);

use App\Enums\AuditEventType;
use App\Models\AuditLog;
use App\Models\IntelligenceErrorFingerprint;
use App\Models\IntelligenceIncident;
use App\Models\IntelligenceRecommendation;
use App\Models\User;
use App\Services\Intelligence\IntelligencePermissions;
use App\Services\Intelligence\System\Detection\DetectionEngine;
use App\Services\Intelligence\System\Detection\RecommendationTemplates;
use App\Services\Intelligence\System\RouteGroups;
use App\Services\Intelligence\System\RunbookCatalog;
use App\Services\Intelligence\System\Telemetry\Metrics;
use App\Services\Intelligence\System\Telemetry\SignalRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * Incident, recommendation and runbook pages: gated by their own grants,
 * diagnostics detail only with the diagnostics grant, every human transition
 * recorded as an event and audited.
 */

function intelUser(array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::findOrCreate('intel-'.md5(implode(',', $permissions)), 'web');
    $role->syncPermissions($permissions);
    $user->assignRole($role);

    return $user;
}

function openIncident(): IntelligenceIncident
{
    $now = CarbonImmutable::now();
    IntelligenceErrorFingerprint::query()->create([
        'fingerprint' => sha1('pdf'), 'exception_class' => 'RuntimeException', 'message_normalized' => 'font {value} missing', 'route_group' => RouteGroups::ADMIN,
        'route_name' => 'id-cards.pdf', 'component' => 'Services/IdCards', 'occurrences' => 15, 'first_seen_at' => $now->subMinutes(20), 'last_seen_at' => $now->subMinute(),
        'sample_context' => ['file' => 'app/Services/IdCards/Renderer.php', 'line' => 42, 'frames' => []],
    ]);
    app(SignalRepository::class)->write([['metric' => Metrics::SCHEDULER_HEARTBEAT, 'component' => 'scheduler', 'value' => 1, 'bucket_start' => $now->subMinutes(5)]]);
    app(DetectionEngine::class)->run($now);

    return IntelligenceIncident::query()->where('rule', 'error_repeat')->firstOrFail();
}

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    Notification::fake();
    foreach (IntelligencePermissions::all() as $name) {
        Permission::findOrCreate($name, 'web');
    }
});

test('incident pages need the incidents grant and hide diagnostics detail without that grant', function (): void {
    $incident = openIncident();
    $viewer = intelUser([IntelligencePermissions::VIEW, IntelligencePermissions::INCIDENTS]);
    $developer = intelUser([IntelligencePermissions::VIEW, IntelligencePermissions::INCIDENTS, IntelligencePermissions::DIAGNOSTICS]);
    $executive = intelUser([IntelligencePermissions::VIEW, IntelligencePermissions::EXECUTIVE]);

    $this->actingAs($executive)->get(route('intelligence.incidents.index'))->assertForbidden();

    $this->actingAs($viewer)->get(route('intelligence.incidents.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Intelligence/Incidents/Index')
            ->has('incidents.data', 1)
            ->where('incidents.data.0.reference', $incident->reference)
            ->where('counts.medium', 1)
            ->where('can.manage', false));

    $this->actingAs($viewer)->get(route('intelligence.incidents.show', $incident))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Intelligence/Incidents/Show')
            ->where('incident.rule', 'error_repeat')
            ->where('runbook', RunbookCatalog::forRule('error_repeat'))
            ->has('events', 2)
            ->has('recommendations', 4)
            ->has('fingerprints', 1)
            ->where('fingerprints.0.sample_context', null)
            ->where('fingerprints.0.last_error_id', null));

    $this->actingAs($developer)->get(route('intelligence.incidents.show', $incident))
        ->assertInertia(fn (Assert $page) => $page->where('fingerprints.0.sample_context.file', 'app/Services/IdCards/Renderer.php'));
});

test('a manager moves an incident through its states; each step is an event and an audit row', function (): void {
    $incident = openIncident();
    $viewer = intelUser([IntelligencePermissions::VIEW, IntelligencePermissions::INCIDENTS]);
    $manager = intelUser([IntelligencePermissions::VIEW, IntelligencePermissions::INCIDENTS, IntelligencePermissions::INCIDENTS_MANAGE]);

    $this->actingAs($viewer)->patch(route('intelligence.incidents.update', $incident), ['status' => 'investigating'])->assertForbidden();

    $this->actingAs($manager)->patch(route('intelligence.incidents.update', $incident), ['status' => 'investigating', 'owner' => 'me', 'note' => 'Looking at the font files'])
        ->assertRedirect()->assertSessionHas('flash.type', 'success');
    $incident->refresh();
    expect($incident->status)->toBe('investigating')->and($incident->owner_user_id)->toBe($manager->id);

    $this->actingAs($manager)->patch(route('intelligence.incidents.update', $incident), ['status' => 'resolved', 'resolution_summary' => 'Installed the Ethiopic font.', 'verification_status' => 'verified']);
    $incident->refresh();
    expect($incident->status)->toBe('resolved')->and($incident->verification_status)->toBe('verified')->and($incident->resolution_summary)->toContain('font');

    $this->actingAs($manager)->patch(route('intelligence.incidents.update', $incident), ['status' => 'closed']);
    expect($incident->fresh()->status)->toBe('closed')
        ->and($incident->events()->pluck('event')->all())->toContain('owner_changed', 'status_changed', 'verified', 'resolved', 'closed')
        ->and(AuditLog::query()->where('event_type', AuditEventType::IntelligenceIncidentUpdated->value)->count())->toBe(3);

    $this->actingAs($manager)->patch(route('intelligence.incidents.update', $incident), ['status' => 'bogus'])->assertSessionHasErrors('status');
});

test('recommendations list and review, and runbooks read', function (): void {
    openIncident();
    $reader = intelUser([IntelligencePermissions::VIEW, IntelligencePermissions::RECOMMENDATIONS, IntelligencePermissions::RUNBOOKS]);
    $reviewer = intelUser([IntelligencePermissions::VIEW, IntelligencePermissions::RECOMMENDATIONS, IntelligencePermissions::RECOMMENDATIONS_REVIEW]);
    $recommendation = IntelligenceRecommendation::query()->where('category', 'short_term')->firstOrFail();

    $this->actingAs($reader)->get(route('intelligence.recommendations.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Intelligence/Recommendations/Index')->has('recommendations.data', 4)->where('can.review', false));
    $this->actingAs($reader)->patch(route('intelligence.recommendations.review', $recommendation), ['review_status' => 'useful'])->assertForbidden();

    $this->actingAs($reviewer)->patch(route('intelligence.recommendations.review', $recommendation), ['review_status' => 'accepted', 'note' => 'Doing it this sprint'])
        ->assertRedirect()->assertSessionHas('flash.type', 'success');
    $recommendation->refresh();
    expect($recommendation->review_status)->toBe('accepted')
        ->and($recommendation->reviewed_by)->toBe($reviewer->id)
        ->and($recommendation->body)->toBe('intelligence.recommendations.error_repeat.short_term.body') // the text is never changed by a review
        ->and(AuditLog::query()->where('event_type', AuditEventType::IntelligenceRecommendationReviewed->value)->count())->toBe(1);
    $this->actingAs($reviewer)->patch(route('intelligence.recommendations.review', $recommendation), ['review_status' => 'pending'])->assertSessionHasErrors('review_status');

    $this->actingAs($reader)->get(route('intelligence.runbooks.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Intelligence/Runbooks/Index')->has('runbooks', count(RunbookCatalog::RUNBOOKS))->where('execution_enabled', false));
    $this->actingAs($reader)->get(route('intelligence.runbooks.show', 'queue_backlog'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Intelligence/Runbooks/Show')->has('runbook.steps', 6));
    $this->actingAs($reader)->get('/intelligence/runbooks/not_a_book')->assertNotFound();
    $this->actingAs($reviewer)->get(route('intelligence.runbooks.index'))->assertForbidden();
});

test('every runbook step, rule title and recommendation template has text in both languages', function (): void {
    foreach (RunbookCatalog::all() as $book) {
        foreach (['en', 'am'] as $locale) {
            expect(__(str_replace('intelligence.', 'intelligence_system.', $book['title']), [], $locale))->not->toContain('intelligence_system.');
            foreach ($book['steps'] as $step) {
                expect(__(str_replace('intelligence.', 'intelligence_system.', $step), [], $locale))->not->toContain('intelligence_system.');
            }
        }
    }
    foreach (RecommendationTemplates::rules() as $rule) {
        foreach (['en', 'am'] as $locale) {
            expect(__("intelligence_system.rules.{$rule}.title", [], $locale))->not->toContain('intelligence_system.');
            foreach (RecommendationTemplates::for($rule) as $template) {
                expect(__(str_replace('intelligence.', 'intelligence_system.', $template['body']), [], $locale))->not->toContain('intelligence_system.');
            }
        }
    }
});
