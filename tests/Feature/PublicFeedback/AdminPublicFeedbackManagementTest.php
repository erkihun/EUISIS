<?php

declare(strict_types=1);

use App\Models\OrganizationUnit;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Feedback\Application\Services\PublicFeedbackWorkflow;
use Modules\Feedback\Contracts\Data\ServiceFeedbackStatus;
use Modules\Feedback\Domain\Models\EmployeeServiceFeedback;
use Modules\Feedback\Domain\Models\PublicFeedbackAssignment;
use Modules\Feedback\Domain\Models\PublicFeedbackReportExport;
use Modules\Feedback\Domain\Models\PublicFeedbackStatusHistory;
use Tests\Support\SituationRoomScenario;

/*
 * The admin side of Public Feedback Management for organization-targeted
 * submissions (docs/admin-public-feedback-management.md). Bureau → Subcity A
 * (Woreda A1, A2) / Subcity B (Woreda B1): Feedback, a Tip-off and a
 * Complaint about Woreda A1 reach Woreda A1, Subcity A and the Bureau, and
 * never Woreda A2 or Subcity B — on the list, the detail, the actions, the
 * evidence and the export. Permission decides WHAT, organization scope WHERE.
 */

const ADMIN_ALL = [
    'service_feedback.view', 'service_feedback.tip_offs.view', 'service_feedback.complaints.view', 'service_feedback.view_organization_target',
    'service_feedback.view_employee_target', 'service_feedback.view_sensitive_attachments',
];

beforeEach(function (): void {
    Storage::fake('local');
    app()->setLocale('en');
    $this->withHeader('X-Locale', 'en');
    $this->s = SituationRoomScenario::build();
    foreach ([...ADMIN_ALL, 'service_feedback.view_submitter_identity', 'service_feedback.review', 'service_feedback.tip_offs.review', 'service_feedback.complaints.review',
        'service_feedback.resolve', 'service_feedback.close', 'service_feedback.assign', 'service_feedback.reassign', 'service_feedback.hide', 'service_feedback.export'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    // Only the rows this test makes: the scenario's own complaints are left out of every count below.
    EmployeeServiceFeedback::query()->get()->each->delete();

    $this->rows = [];
    foreach (['feedback', 'suggestion', 'complaint'] as $type) {
        $this->rows[$type] = $this->s->submission($this->s->org['woreda_a1'], $type, [
            'reference_number' => $type === 'feedback' ? null : strtoupper(substr($type, 0, 3)).'-2026-'.random_int(100000, 999999),
            'target_name_snapshot' => 'Woreda A1',
            'submitter_identity_mode' => $type === 'suggestion' ? 'anonymous' : 'identified',
            'status' => $type === 'feedback' ? 'pending' : 'unrouted',
        ]);
    }
});

function apfViewer(string $orgKey, array $extra = []): User
{
    return test()->s->viewer([...ADMIN_ALL, ...$extra], $orgKey);
}

/** @return array<string, mixed> the Inertia props of a page */
function apfProps(User $user, string $url): array
{
    $props = null;
    test()->actingAs($user)->get($url)->assertOk()->assertInertia(function (Assert $page) use (&$props): void {
        $props = $page->toArray()['props'];
    });

    return $props;
}

/** A case route of the module the type belongs to. */
function apfCase(string $type, string $action): string
{
    return ($type === 'suggestion' ? 'tip-offs' : 'public-complaints').'.cases.'.$action;
}

function apfDetailUrl(EmployeeServiceFeedback $row): string
{
    return $row->type->value === 'feedback'
        ? route('service-feedback.admin.show', $row->id)
        : route(($row->type->value === 'suggestion' ? 'tip-offs' : 'public-complaints').'.cases.show', $row->id);
}

// ── Scope: roll-up and sibling isolation ─────────────────────────────────

it('shows Woreda A1\'s Feedback, Tip-off and Complaint to the woreda, its sub-city and its bureau, and to no sibling', function (string $orgKey, int $expected): void {
    $viewer = apfViewer($orgKey);
    // Each module its own queue (owner rule: nothing shared).
    $queues = array_map(fn (string $name) => apfProps($viewer, route($name)), ['tip-offs.unrouted', 'public-complaints.unrouted']);
    expect(array_sum(array_map(fn ($props) => $props['entries']['total'], $queues)))->toBe(intdiv($expected * 2, 3));

    foreach ($this->rows as $row) {
        $response = $this->actingAs($viewer)->get(apfDetailUrl($row));
        $expected === 3 ? $response->assertOk() : $response->assertForbidden();
    }
})->with([
    'the woreda itself' => ['woreda_a1', 3],
    'its sub-city' => ['subcity_a', 3],
    'its bureau' => ['bureau', 3],
    'a sibling woreda' => ['woreda_a2', 0],
    'another sub-city' => ['subcity_b', 0],
]);

it('scopes employee-targeted submissions the same way, and only for holders of the employee-target grant', function (): void {
    $employee = $this->s->people['woreda_a1'][0];
    $row = $this->s->submission($this->s->org['woreda_a1'], 'complaint', [
        'target_type' => 'employee', 'source_type' => 'employee_card_qr', 'organization_target_id' => null,
        'employee_id' => $employee->id, 'target_name_snapshot' => 'Officer woreda_a1', 'status' => 'unrouted',
    ]);

    $props = apfProps(apfViewer('subcity_a'), route('public-complaints.unrouted', ['target_type' => 'employee']));
    expect($props['entries']['total'])->toBe(1)
        ->and($props['entries']['data'][0]['target_type'])->toBe('employee')
        ->and($props['entries']['data'][0]['target_name'])->toBe($employee->full_name);

    expect(apfProps(apfViewer('woreda_a2'), route('public-complaints.unrouted', ['target_type' => 'employee']))['entries']['total'])->toBe(0);

    // Without the employee-target grant the row is neither listed nor opened.
    $orgOnly = $this->s->viewer(['service_feedback.view', 'service_feedback.tip_offs.view', 'service_feedback.complaints.view', 'service_feedback.view_organization_target'], 'bureau');
    expect(apfProps($orgOnly, route('public-complaints.unrouted'))['entries']['total'])->toBe(1);
    $this->actingAs($orgOnly)->get(apfDetailUrl($row))->assertForbidden();
});

it('keeps Tip-offs and Complaints from a user who holds only the service feedback grant', function (): void {
    $general = $this->s->viewer(['service_feedback.view', 'service_feedback.view_organization_target', 'service_feedback.export'], 'bureau');

    // Owner rule: three separate modules, each with its own grant.
    $this->actingAs($general)->get(route('tip-offs.unrouted'))->assertForbidden();
    $this->actingAs($general)->get(route('public-complaints.unrouted'))->assertForbidden();
    $this->actingAs($general)->get(route('tip-offs.dashboard'))->assertForbidden();
    $this->actingAs($general)->get(route('public-complaints.dashboard'))->assertForbidden();
    $this->actingAs($general)->get(apfDetailUrl($this->rows['suggestion']))->assertForbidden();
    $this->actingAs($general)->get(apfDetailUrl($this->rows['complaint']))->assertForbidden();

    // The service feedback report carries feedback only.
    $this->actingAs($general)->post(route('service-feedback.submission-reports.export'), ['format' => 'csv'])->assertRedirect();
    $csv = Storage::disk('local')->get(PublicFeedbackReportExport::query()->sole()->path);
    expect($csv)->not->toContain($this->rows['complaint']->reference_number)
        ->and($csv)->not->toContain($this->rows['suggestion']->reference_number);
});

it('returns nothing for forged organization, unit and handler filters', function (array $query): void {
    $props = apfProps(apfViewer('subcity_a'), route('public-complaints.unrouted', $query));

    expect($props['entries']['total'])->toBe(0)->and($props['overview']['total'])->toBe(0);
})->with([
    'a sibling organization' => [fn () => ['organization_id' => test()->s->org['subcity_b']->id]],
    'the parent organization' => [fn () => ['organization_id' => test()->s->org['bureau']->id]],
    'an unknown unit' => [fn () => ['organization_unit_id' => (string) Str::uuid()]],
    'an unknown handler' => [fn () => ['handler_type' => 'user', 'handler_id' => '999999']],
]);

it('filters by the relevant work unit of the chosen institution only, and shows the unit as recorded or General / Not sure', function (): void {
    $permits = OrganizationUnit::query()->create(['organization_id' => $this->s->org['woreda_a1']->id, 'unit_type' => 'office', 'code' => 'A1-PERMITS', 'name_en' => 'Permits office', 'status' => 'active']);
    $foreign = OrganizationUnit::query()->create(['organization_id' => $this->s->org['woreda_b1']->id, 'unit_type' => 'office', 'code' => 'B1-PERMITS', 'name_en' => 'B1 Permits office', 'status' => 'active']);
    $this->rows['complaint']->forceFill(['organization_unit_id' => $permits->id, 'organization_unit_name_snapshot' => 'Permits office', 'organization_unit_type_snapshot' => 'office', 'unit_selection_mode' => 'SELECTED'])->save();
    $this->rows['suggestion']->forceFill(['unit_selection_mode' => 'GENERAL'])->save();
    $permits->update(['name_en' => 'Renamed office']);
    $viewer = apfViewer('subcity_a');

    // The unit filter offers the chosen institution's units, and never those of an institution outside the scope.
    $props = apfProps($viewer, route('public-complaints.unrouted', ['organization_id' => $this->s->org['woreda_a1']->id]));
    expect(collect($props['filterOptions']['units'])->pluck('id')->all())->toBe([$permits->id]);
    expect(apfProps($viewer, route('public-complaints.unrouted'))['filterOptions']['units'])->toBe([])
        ->and(apfProps($viewer, route('public-complaints.unrouted', ['organization_id' => $this->s->org['woreda_b1']->id]))['filterOptions']['units'])->toBe([]);

    $props = apfProps($viewer, route('public-complaints.unrouted', ['organization_id' => $this->s->org['woreda_a1']->id, 'organization_unit_id' => $permits->id]));
    expect($props['entries']['total'])->toBe(1)
        ->and($props['entries']['data'][0]['type'])->toBe('complaint')
        // The name at submission, not the renamed unit.
        ->and($props['entries']['data'][0]['organization_unit'])->toBe('Permits office');
    expect(apfProps($viewer, route('public-complaints.unrouted', ['organization_unit_id' => $foreign->id]))['entries']['total'])->toBe(0);

    $rows = collect(apfProps($viewer, route('tip-offs.unrouted'))['entries']['data'])->keyBy('type');
    expect($rows['suggestion']['organization_unit'])->toBeNull()
        ->and($rows['suggestion']['unit_selection_mode'])->toBe('GENERAL');

    $detail = apfProps($viewer, apfDetailUrl($this->rows['complaint']));
    expect($detail['entry']['organization_unit'])->toBe('Permits office')
        ->and($detail['entry']['unit_selection_mode'])->toBe('SELECTED')
        ->and($detail['organizationForm']['service_point'])->toBe('Permits office');
});

// ── List, filters, counts, unrouted queue ───────────────────────────────

it('lists each module only its own cases, with target, organization and status, and none of the submitter or the narrative', function (): void {
    $viewer = apfViewer('bureau', ['service_feedback.view_submitter_identity']);
    $tipOffs = apfProps($viewer, route('tip-offs.unrouted'));
    $props = apfProps($viewer, route('public-complaints.unrouted'));
    $rows = collect($props['entries']['data'])->keyBy('type');

    expect(collect($tipOffs['entries']['data'])->pluck('type')->unique()->all())->toBe(['suggestion'])
        ->and($tipOffs['filterOptions']['types'])->toBe(['suggestion'])
        ->and($rows->keys()->all())->toBe(['complaint'])
        ->and($rows['complaint']['target_type'])->toBe('organization')
        ->and($rows['complaint']['target_name'])->toBe('Woreda A1')
        ->and($rows['complaint']['organization'])->toBe('Woreda A1')
        ->and($rows['complaint']['status'])->toBe('unrouted')
        ->and($rows['complaint']['url'])->toBe(apfDetailUrl($this->rows['complaint']));

    // Even with the identity grant, the list carries no submitter and no narrative.
    expect(json_encode($props['entries']))->not->toContain('Private Submitter')
        ->not->toContain('+251911000000')
        ->not->toContain('Private narrative');
});

it('filters each module queue by target and identity, and reconciles the cards with the list', function (): void {
    $viewer = apfViewer('subcity_a');

    expect(apfProps($viewer, route('public-complaints.unrouted', ['target_type' => 'organization']))['entries']['total'])->toBe(1)
        ->and(apfProps($viewer, route('public-complaints.unrouted', ['target_type' => 'employee']))['entries']['total'])->toBe(0)
        ->and(apfProps($viewer, route('tip-offs.unrouted', ['identity_mode' => 'anonymous']))['entries']['data'][0]['type'])->toBe('suggestion');

    // The cards count only the module's own cases in scope; their parts add up to the whole.
    $props = apfProps($viewer, route('public-complaints.unrouted'));
    $inScope = EmployeeServiceFeedback::query()->where('type', 'complaint')->whereIn('organization_id', [$this->s->org['subcity_a']->id, $this->s->org['woreda_a1']->id, $this->s->org['woreda_a2']->id])->count();
    expect(array_sum($props['overview']['by_type']))->toBe($props['overview']['total'])
        ->and($props['overview']['total'])->toBe($inScope);
});

it('finds a submission by its exact reference and by the institution\'s name', function (): void {
    $viewer = apfViewer('bureau');
    $reference = $this->rows['complaint']->reference_number;

    expect(collect(apfProps($viewer, route('public-complaints.unrouted', ['q' => strtolower($reference)]))['entries']['data'])->pluck('id')->all())->toBe([$this->rows['complaint']->id])
        ->and(apfProps($viewer, route('public-complaints.unrouted', ['q' => 'Woreda A1']))['entries']['total'])->toBe(1)
        ->and(apfProps($viewer, route('public-complaints.unrouted', ['q' => 'Private narrative']))['entries']['total'])->toBe(0);
});

it('keeps unrouted Tip-offs and Complaints in their own queue until a handler is assigned', function (): void {
    $viewer = apfViewer('subcity_a', ['service_feedback.assign']);
    $props = apfProps($viewer, route('public-complaints.unrouted'));
    $tipOffs = apfProps($viewer, route('tip-offs.unrouted'));

    expect($props['queue'])->toBe('unrouted')
        ->and(collect($props['entries']['data'])->pluck('type')->all())->toBe(['complaint'])
        ->and(collect($tipOffs['entries']['data'])->pluck('type')->all())->toBe(['suggestion'])
        ->and($props['overview']['by_status']['unrouted'])->toBe(1);

    // Assigning moves it out of the queue; the institution it is about never changes.
    $this->actingAs($viewer)->post(route('public-complaints.cases.assign', ['submission' => $this->rows['complaint']->id]), [
        'handler_type' => 'user', 'handler_id' => (string) $viewer->id, 'reason' => 'Taking this one',
    ])->assertSessionHasNoErrors();

    $complaint = $this->rows['complaint']->fresh();
    expect($complaint->status)->toBe(ServiceFeedbackStatus::Assigned)
        ->and($complaint->organization_target_id)->toBe($this->s->org['woreda_a1']->id)
        ->and($complaint->organization_id)->toBe($this->s->org['woreda_a1']->id)
        ->and(PublicFeedbackAssignment::query()->where('submission_id', $complaint->id)->whereNull('ended_at')->count())->toBe(1)
        ->and(apfProps($viewer, route('public-complaints.unrouted'))['entries']['total'])->toBe(0)
        ->and(apfProps($viewer, route('tip-offs.unrouted'))['entries']['total'])->toBe(1);
});

// ── Actions: scope and workflow ─────────────────────────────────────────

it('refuses a sibling every action and every file on a submission it cannot see', function (): void {
    $complaint = $this->rows['complaint'];
    Storage::disk('local')->put('service-feedback/'.$complaint->id.'/e.png', 'png-bytes');
    $file = $complaint->attachments()->create(['disk' => 'local', 'path' => 'service-feedback/'.$complaint->id.'/e.png', 'original_name' => 'e.png',
        'mime_type' => 'image/png', 'size_bytes' => 9, 'sha256' => hash('sha256', 'png-bytes'), 'scan_status' => 'not_scanned']);
    $sibling = apfViewer('woreda_a2', ['service_feedback.complaints.review', 'service_feedback.assign', 'service_feedback.review', 'service_feedback.tip_offs.review', 'service_feedback.hide']);
    $params = ['submission' => $complaint->id];

    $this->actingAs($sibling)->get(route('public-complaints.cases.attachments.download', $params + ['attachment' => $file->id]))->assertForbidden();
    $this->actingAs($sibling)->post(route(apfCase($type ?? 'complaint', 'assign'), $params), ['handler_type' => 'user', 'handler_id' => (string) $sibling->id])->assertForbidden();
    $this->actingAs($sibling)->post(route(apfCase($type ?? 'complaint', 'transition'), $params), ['status' => 'assigned'])->assertForbidden();
    $this->actingAs($sibling)->post(route('service-feedback.admin.review', $this->rows['feedback']), ['status' => 'reviewed'])->assertForbidden();
    $this->actingAs($sibling)->post(route('service-feedback.admin.hide', $this->rows['feedback']))->assertForbidden();

    // The parent with the evidence grant downloads it; a file of another submission, or none at all, is not found.
    $parent = apfViewer('subcity_a');
    $this->actingAs($parent)->get(route('public-complaints.cases.attachments.download', $params + ['attachment' => $file->id]))->assertOk();
    $this->actingAs($parent)->get(route('public-complaints.cases.attachments.download', ['submission' => $this->rows['suggestion']->id, 'attachment' => $file->id]))->assertNotFound();
    $this->actingAs($parent)->get(route('public-complaints.cases.attachments.download', $params + ['attachment' => (string) Str::uuid()]))->assertNotFound();

    expect($complaint->fresh()->status)->toBe(ServiceFeedbackStatus::Unrouted)
        ->and($this->rows['feedback']->fresh()->status)->toBe(ServiceFeedbackStatus::Pending);
});

it('moves a Feedback only along its workflow, recording every step, and closes it with the close grant only', function (): void {
    $feedback = $this->rows['feedback'];
    $reviewer = apfViewer('subcity_a', ['service_feedback.review', 'service_feedback.tip_offs.review']);

    $this->actingAs($reviewer)->post(route('service-feedback.admin.review', $feedback), ['status' => 'reviewed', 'review_note' => 'Read and shared with the office.'])->assertSessionHasNoErrors();
    $this->actingAs($reviewer)->post(route('service-feedback.admin.review', $feedback), ['status' => 'resolved'])->assertSessionHasNoErrors();
    // Resolved cannot go back to reviewed.
    $this->actingAs($reviewer)->post(route('service-feedback.admin.review', $feedback), ['status' => 'reviewed'])->assertSessionHasErrors('status');
    // Closing is the close grant, not the review grant.
    $this->actingAs($reviewer)->post(route('service-feedback.admin.review', $feedback), ['status' => 'closed'])->assertForbidden();
    $this->actingAs(apfViewer('subcity_a', ['service_feedback.review', 'service_feedback.tip_offs.review', 'service_feedback.close']))
        ->post(route('service-feedback.admin.review', $feedback), ['status' => 'closed'])->assertSessionHasNoErrors();

    $feedback->refresh();
    expect($feedback->status)->toBe(ServiceFeedbackStatus::Closed)
        ->and($feedback->acknowledged_at)->not->toBeNull()
        ->and($feedback->resolved_at)->not->toBeNull()
        ->and($feedback->closed_at)->not->toBeNull()
        ->and($feedback->review_note)->toBe('Read and shared with the office.')
        ->and(PublicFeedbackStatusHistory::query()->where('submission_id', $feedback->id)->orderBy('transitioned_at')->orderBy('id')->pluck('to_status')->all())
        ->toBe(['reviewed', 'resolved', 'closed']);

    // The detail page shows that history and offers no further move.
    $props = apfProps($reviewer, route('service-feedback.admin.show', $feedback));
    expect(collect($props['history'])->pluck('to_status')->all())->toBe(['reviewed', 'resolved', 'closed'])
        ->and($props['allowedTransitions'])->toBe([])
        ->and($props['feedback']['target_type'])->toBe('organization')
        ->and($props['feedback']['target_organization']['en'])->toBe('Woreda A1');
});

it('restores a hidden Feedback to the status it had, with both steps in the history', function (): void {
    $feedback = $this->rows['feedback'];
    $moderator = apfViewer('subcity_a', ['service_feedback.review', 'service_feedback.tip_offs.review', 'service_feedback.hide']);
    $this->actingAs($moderator)->post(route('service-feedback.admin.review', $feedback), ['status' => 'reviewed'])->assertSessionHasNoErrors();

    $this->actingAs($moderator)->post(route('service-feedback.admin.hide', $feedback))->assertRedirect();
    expect($feedback->fresh()->status)->toBe(ServiceFeedbackStatus::Hidden);
    $this->actingAs($moderator)->post(route('service-feedback.admin.hide', $feedback))->assertRedirect();

    expect($feedback->fresh()->status)->toBe(ServiceFeedbackStatus::Reviewed)
        ->and(PublicFeedbackStatusHistory::query()->where('submission_id', $feedback->id)->orderBy('transitioned_at')->orderBy('id')->get(['from_status', 'to_status'])->map(fn ($row): string => $row->from_status.'>'.$row->to_status)->all())
        ->toBe(['pending>reviewed', 'reviewed>hidden', 'hidden>reviewed']);
});

it('runs a Complaint and a Tip-off through their workflows and refuses a skipped step', function (string $type, array $steps): void {
    $row = $this->rows[$type];
    $handler = apfViewer('bureau', ['service_feedback.assign', 'service_feedback.review', 'service_feedback.tip_offs.review', 'service_feedback.complaints.review', 'service_feedback.resolve', 'service_feedback.close']);
    $params = ['submission' => $row->id];

    // Skipping straight to the outcome is refused.
    $this->actingAs($handler)->post(route(apfCase($type ?? 'complaint', 'transition'), $params), ['status' => 'closed'])->assertSessionHasErrors('status');

    $this->actingAs($handler)->post(route(apfCase($type ?? 'complaint', 'assign'), $params), ['handler_type' => 'user', 'handler_id' => (string) $handler->id])->assertSessionHasNoErrors();
    foreach ($steps as $status) {
        $this->actingAs($handler)->post(route(apfCase($type ?? 'complaint', 'transition'), $params), ['status' => $status, 'reason' => 'Step '.$status])->assertSessionHasNoErrors();
    }

    $row->refresh();
    expect($row->status->value)->toBe('closed')
        ->and($row->organization_target_id)->toBe($this->s->org['woreda_a1']->id)
        ->and(PublicFeedbackStatusHistory::query()->where('submission_id', $row->id)->pluck('to_status')->all())->toBe(['assigned', ...$steps]);
})->with([
    'complaint' => ['complaint', ['under_review', 'resolved', 'closed']],
    'tip-off' => ['suggestion', ['under_review', 'accepted', 'closed']],
]);

// ── Privacy and language ────────────────────────────────────────────────

it('sends the complainant\'s contact only to a holder of the identity grant', function (): void {
    $url = apfDetailUrl($this->rows['complaint']);

    $without = apfProps(apfViewer('subcity_a'), $url);
    expect($without['entry']['client_contact'])->toBeNull()
        ->and($without['entry']['client_name'])->toBeNull()
        ->and($without['entry']['identity_hidden'])->toBeTrue()
        ->and(json_encode($without))->not->toContain('+251911000000');

    $with = apfProps(apfViewer('subcity_a', ['service_feedback.view_submitter_identity']), $url);
    expect($with['entry']['client_contact'])->toBe('+251911000000');
});

it('writes an Amharic export with Amharic labels, names and Ethiopian dates', function (): void {
    $this->rows['complaint']->forceFill(['submitted_at' => '2026-10-17 10:00:00'])->save();
    $viewer = apfViewer('bureau', ['service_feedback.export']);

    $this->actingAs($viewer)->withHeader('X-Locale', 'am')
        ->post(route('public-complaints.reports.export'), ['format' => 'csv'])->assertRedirect();

    $export = PublicFeedbackReportExport::query()->sole();
    $csv = Storage::disk('local')->get($export->path);
    expect($export->locale)->toBe('am')
        ->and($csv)->toContain('ቅሬታ')
        ->toContain('ያልተላለፈ')
        ->toContain('ተቋም')
        ->toContain('Woreda A1 (am)')
        // 17 October 2026 is 7 Tikimt 2019 in the Ethiopian calendar.
        ->toContain('2019')
        ->not->toContain('2026-10-17');
});

it('records the workflow even when the resolution notice cannot be delivered', function (): void {
    $complaint = $this->rows['complaint'];
    $complaint->forceFill(['status' => 'under_review'])->save();
    // A mail transport that fails: the transition commits, and the notice is the only casualty.
    config(['mail.default' => 'failover', 'mail.mailers.failover' => ['transport' => 'failover', 'mailers' => []]]);

    try {
        app(PublicFeedbackWorkflow::class)->transition($complaint, ServiceFeedbackStatus::Resolved, apfViewer('bureau'), 'Fixed');
    } catch (Throwable) {
        // Delivery trouble may surface here; what matters is the committed state below.
    }

    expect($complaint->fresh()->status)->toBe(ServiceFeedbackStatus::Resolved)
        ->and(PublicFeedbackStatusHistory::query()->where('submission_id', $complaint->id)->where('to_status', 'resolved')->exists())->toBeTrue();
});
