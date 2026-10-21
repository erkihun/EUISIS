<?php

declare(strict_types=1);

use App\Enums\AuditEventType;
use App\Models\AuditLog;
use App\Models\IntelligenceErrorFingerprint;
use App\Models\IntelligenceIncident;
use App\Models\IntelligenceIncidentEvent;
use App\Models\IntelligenceRecommendation;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Intelligence\AI\RootCauseAssistant;
use App\Services\Intelligence\IntelligencePermissions;
use App\Services\Intelligence\System\Detection\DetectionEngine;
use App\Services\Intelligence\System\RouteGroups;
use App\Services\Intelligence\System\Telemetry\Metrics;
use App\Services\Intelligence\System\Telemetry\SignalRepository;
use App\Services\SystemSettings\SystemSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * The root-cause assistant (docs/intelligence-ai-governance.md): the explain
 * endpoint needs the diagnostics grant and an available gateway; the bundle
 * sent out carries signatures and file:line, never secrets; the answer is
 * stored with its confidence, its checks become AI recommendations awaiting
 * review, and everything is recorded as events and audit.
 */

function rcUser(array $permissions): User
{
    $user = User::factory()->create();
    $role = Role::findOrCreate('rc-'.md5(implode(',', $permissions)), 'web');
    $role->syncPermissions($permissions);
    $user->assignRole($role);

    return $user;
}

function rcSetting(string $key, string $value): void
{
    SystemSetting::query()->updateOrCreate(['group' => 'intelligence', 'key' => $key], ['value' => $value, 'type' => 'string', 'label_en' => $key, 'label_am' => $key]);
    app(SystemSettingsService::class)->clearCache();
}

function rcIncident(): IntelligenceIncident
{
    $now = CarbonImmutable::now();
    IntelligenceErrorFingerprint::query()->create([
        'fingerprint' => sha1('rc'), 'exception_class' => 'RuntimeException', 'message_normalized' => 'token sk_live_ABCDEFGHIJKLMNOP rejected for {value}', 'route_group' => RouteGroups::ADMIN,
        'route_name' => 'id-cards.pdf', 'component' => 'Services/IdCards', 'occurrences' => 15, 'first_seen_at' => $now->subMinutes(20), 'last_seen_at' => $now->subMinute(),
        'sample_context' => ['file' => 'app/Services/IdCards/Renderer.php', 'line' => 42, 'frames' => ['secret frame contents']],
    ]);
    app(SignalRepository::class)->write([['metric' => Metrics::SCHEDULER_HEARTBEAT, 'component' => 'scheduler', 'value' => 1, 'bucket_start' => $now->subMinutes(5)]]);
    app(DetectionEngine::class)->run($now);

    return IntelligenceIncident::query()->where('rule', 'error_repeat')->firstOrFail();
}

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    Cache::flush();
    Notification::fake();
    foreach (IntelligencePermissions::all() as $name) {
        Permission::findOrCreate($name, 'web');
    }
});

test('explain needs the diagnostics grant and an available gateway', function (): void {
    $incident = rcIncident();
    $viewer = rcUser([IntelligencePermissions::VIEW, IntelligencePermissions::INCIDENTS]);
    $developer = rcUser([IntelligencePermissions::VIEW, IntelligencePermissions::INCIDENTS, IntelligencePermissions::DIAGNOSTICS]);

    $this->actingAs($viewer)->post(route('intelligence.incidents.explain', $incident))->assertForbidden();

    $this->actingAs($developer)->get(route('intelligence.incidents.show', $incident))
        ->assertInertia(fn (Assert $page) => $page->where('ai.available', false)->where('assessment', null));
    $this->actingAs($developer)->from(route('intelligence.incidents.show', $incident))->post(route('intelligence.incidents.explain', $incident))
        ->assertRedirect(route('intelligence.incidents.show', $incident))->assertSessionHas('flash.type', 'warning');
    expect(IntelligenceIncidentEvent::query()->where('event', 'ai_requested')->count())->toBe(0);
});

test('the bundle carries signatures and file:line, redacted, and never stack contents', function (): void {
    $incident = rcIncident();
    $bundle = app(RootCauseAssistant::class)->bundle($incident);
    $json = json_encode($bundle);

    expect($bundle['incident']['rule'])->toBe('error_repeat')
        ->and($bundle['related_errors'][0]['file'])->toBe('app/Services/IdCards/Renderer.php')
        ->and($bundle['related_errors'][0]['line'])->toBe(42)
        ->and($json)->not->toContain('secret frame contents')
        ->and($json)->not->toContain('sk_live_ABCDEFGHIJKLMNOP')
        ->and($bundle['component_states'])->toHaveKey('scheduler');
});

test('a successful assessment is stored on the incident with AI recommendations, events and an audit entry', function (): void {
    rcSetting('ai_enabled', '1');
    rcSetting('ai_provider', 'openai_compatible');
    rcSetting('ai_outbound_data_classes', 'aggregates,structured_signals,redacted_logs');
    config(['intelligence.ai.openai_compatible' => ['base_url' => 'https://ai.example.test', 'api_key' => 'test-key', 'model' => 'test-model']]);
    Http::fake(['https://ai.example.test/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
        'observed_symptom' => 'PDF rendering fails repeatedly', 'likely_causes' => [['cause' => 'A font file is missing on the server', 'confidence' => 'medium', 'why' => 'The message names a missing font']],
        'alternative_explanations' => ['A deployment changed the font path'], 'recommended_next_checks' => ['Check the fonts directory exists', 'Read the last error id'],
        'suggested_areas_to_inspect' => ['app/Services/IdCards/Renderer.php'], 'overall_confidence' => 'medium', 'caveats' => 'Single signal',
    ])]]], 'usage' => ['completion_tokens' => 120]], 200)]);

    $incident = rcIncident();
    $developer = rcUser([IntelligencePermissions::VIEW, IntelligencePermissions::INCIDENTS, IntelligencePermissions::DIAGNOSTICS]);

    // The queue is sync in tests, so the job runs inside the request.
    $this->actingAs($developer)->post(route('intelligence.incidents.explain', $incident))->assertRedirect()->assertSessionHas('flash.type', 'success');

    $incident->refresh();
    expect($incident->probable_cause)->toContain('font file is missing')
        ->and($incident->cause_confidence)->toBe('medium')
        ->and($incident->ai_run_id)->not->toBeNull()
        ->and($incident->recommendation['ai_assessment']['overall_confidence'])->toBe('medium')
        ->and(IntelligenceRecommendation::query()->where('incident_id', $incident->id)->where('generated_by', 'ai')->where('review_status', 'pending')->count())->toBe(2)
        ->and(IntelligenceIncidentEvent::query()->where('incident_id', $incident->id)->whereIn('event', ['ai_requested', 'ai_explained'])->count())->toBe(2)
        ->and(AuditLog::query()->where('event_type', AuditEventType::IntelligenceIncidentExplained->value)->count())->toBe(1);

    // The page renders the assessment for diagnostics, not for a plain incidents viewer.
    $this->actingAs($developer)->get(route('intelligence.incidents.show', $incident))
        ->assertInertia(fn (Assert $page) => $page->where('assessment.observed_symptom', 'PDF rendering fails repeatedly')->where('assessment_meta.prompt_version', 'v2'));
    $this->actingAs(rcUser([IntelligencePermissions::VIEW, IntelligencePermissions::INCIDENTS]))->get(route('intelligence.incidents.show', $incident))
        ->assertInertia(fn (Assert $page) => $page->where('assessment', null));
});
