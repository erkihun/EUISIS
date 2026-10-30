<?php

declare(strict_types=1);

use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\ServiceMonitoring\Application\Actions\SaveSection1Entry;
use Modules\ServiceMonitoring\Contracts\Data\ServiceMonitoringRoles;
use Modules\ServiceMonitoring\Domain\Models\AccountabilityEntry;
use Modules\ServiceMonitoring\Domain\Models\SupportAssignment;
use Tests\Support\ServiceMonitoringScenario;

/*
 * Authorization and organization scope on every read and write
 * (docs/service-monitoring-security.md): sibling woredas and other
 * sub-cities stay apart, Section I names are masked without the grant,
 * child rows cannot be reached through another submission.
 */

beforeEach(function (): void {
    Notification::fake();
    $this->s = ServiceMonitoringScenario::build();
    $this->s->at(1);
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('keeps a woreda out of its sibling woreda', function (): void {
    $a1 = $this->s->start('wA1');
    $a2Head = $this->s->head('wA2');

    $this->actingAs($a2Head)->get(route('service-monitoring.submissions.show', $a1))->assertForbidden();
    $this->actingAs($a2Head)->get(route('service-monitoring.submissions.edit', $a1))->assertForbidden();
    $this->actingAs($a2Head)->put(route('service-monitoring.submissions.section2', $a1), ['values' => ['led_count' => 1]])->assertForbidden();
    $this->actingAs($a2Head)->post(route('service-monitoring.submissions.start', [$this->s->period(1), $this->s->p['wA1']]))->assertForbidden();
    $this->actingAs($a2Head)->get(route('service-monitoring.submissions.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ServiceMonitoring/Submissions/Index', false)->where('submissions.data', []));
});

it('keeps one sub-city out of another sub-city and its woredas', function (): void {
    $b1 = $this->s->submit($this->s->complete($this->s->start('wB1')));
    $reviewerA = $this->s->reviewer('subA');

    $this->actingAs($reviewerA)->get(route('service-monitoring.submissions.show', $b1))->assertForbidden();
    $this->actingAs($reviewerA)->post(route('service-monitoring.review.accept', $b1))->assertForbidden();
    $this->actingAs($reviewerA)->post(route('service-monitoring.consolidation.certify', [$this->s->period(1), $this->s->p['subB']]))->assertForbidden();
    $this->actingAs($reviewerA)->get(route('service-monitoring.review.queue'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ServiceMonitoring/Review/Queue', false)->where('pending', []));

    // The right sub-city may.
    $this->actingAs($this->s->reviewer('subB'))->post(route('service-monitoring.review.accept', $b1))->assertSessionHasNoErrors();
});

it('lets only the city review sub-city and center reports, and nobody review their own submission', function (): void {
    $sub = $this->s->submit($this->s->complete($this->s->start('subA')));
    $this->actingAs($this->s->reviewer('subA'))->post(route('service-monitoring.review.accept', $sub))->assertForbidden();

    $coordinator = $this->s->coordinator();
    $own = $this->s->complete($this->s->start('center', 1, $coordinator));
    $this->s->submit($own, $coordinator);
    $this->actingAs($coordinator)->post(route('service-monitoring.review.accept', $own))->assertForbidden();

    $this->actingAs($coordinator)->post(route('service-monitoring.review.accept', $sub))->assertSessionHasNoErrors();
});

it('masks Section I names without view_named and audits named views', function (): void {
    $submission = $this->s->start('wA1');
    app(SaveSection1Entry::class)->execute($submission, [
        'subject_type' => 'customer', 'full_name' => 'Secret Customer', 'role_category' => 'customer', 'reason_category' => 'theft_corruption',
        'reason_detail' => 'Offered money.', 'final_action' => 'legal_action', 'action_detail' => 'Police.', 'action_date' => '2026-08-15', 'office_name' => 'Land office',
    ], $this->s->head('wA1'));

    $reporter = $this->s->reporter('wA1');
    $this->actingAs($reporter)->get(route('service-monitoring.submissions.edit', $submission))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ServiceMonitoring/Submissions/Edit', false)
            ->where('section1.named', false)->where('section1.entries.0.full_name', null)->where('section1.entries.0.reason_detail', null)
            ->where('section1.entries.0.final_action', 'legal_action'));

    $head = $this->s->head('wA1');
    $this->actingAs($head)->get(route('service-monitoring.submissions.show', $submission))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ServiceMonitoring/Submissions/Show', false)
            ->where('section1.named', true)->where('section1.entries.0.full_name', 'Secret Customer'));
    expect(AuditLog::query()->where('event_type', 'service_monitoring.named_data_viewed')->where('actor_user_id', $head->id)->exists())->toBeTrue();

    // A support officer sees the report but not the names.
    $support = $this->s->user(ServiceMonitoringRoles::SUPPORT_OFFICER_PERMISSIONS, ['center']);
    SupportAssignment::query()->create(['participant_id' => $this->s->p['wA1']->id, 'user_id' => $support->id]);
    $this->s->forget();
    $this->actingAs($support)->get(route('service-monitoring.submissions.show', $submission))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('section1.named', false));
});

it('binds Section rows only through their own submission', function (): void {
    $a1 = $this->s->start('wA1');
    $a1Bis = $this->s->start('center');
    app(SaveSection1Entry::class)->execute($a1Bis, [
        'subject_type' => 'customer', 'role_category' => 'customer', 'reason_category' => 'malpractice',
        'reason_detail' => 'A forged receipt.', 'final_action' => 'other', 'action_detail' => 'Refused service.', 'action_date' => '2026-08-15',
    ], $this->s->head('center'));
    $foreignEntry = AccountabilityEntry::query()->sole();

    $this->actingAs($this->s->head('wA1'))->delete(route('service-monitoring.submissions.section1.destroy', [$a1, $foreignEntry]))->assertNotFound();
    expect(AccountabilityEntry::query()->count())->toBe(1);
});

it('requires the module permission for every page', function (): void {
    $nobody = $this->s->user(['dashboard.view'], ['wA1']);
    foreach (['dashboard', 'periods.index', 'participants.index', 'submissions.index', 'review.queue', 'verification.index', 'clarifications.index', 'visits.index', 'action-items.index', 'reports.index', 'settings.edit', 'consolidation.city', 'consolidation.sub-city'] as $name) {
        $this->actingAs($nobody)->get(route("service-monitoring.{$name}"))->assertForbidden();
    }
});

it('gates exports on reports.export and names on export_named', function (): void {
    $this->s->accepted('wA1');
    $head = $this->s->head('wA1');
    $payload = ['scope_type' => 'city', 'fiscal_year_id' => $this->s->year->id, 'quarter' => 1, 'range' => 'quarter', 'format' => 'xlsx', 'named' => true];

    $this->actingAs($head)->post(route('service-monitoring.exports.store'), $payload)->assertForbidden();
    // A sub-city reviewer may export, but not the city.
    $this->actingAs($this->s->reviewer('subA'))->post(route('service-monitoring.exports.store'), $payload)->assertForbidden();
    $this->actingAs($this->s->reviewer('subA'))->post(route('service-monitoring.exports.store'), [...$payload, 'scope_type' => 'sub_city', 'scope_id' => $this->s->p['subB']->id])->assertForbidden();
});
