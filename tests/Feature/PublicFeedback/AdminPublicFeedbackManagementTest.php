<?php

declare(strict_types=1);

use App\Enums\ServiceFeedbackStatus;
use App\Models\EmployeeServiceFeedback;
use App\Models\Permission;
use App\Models\PublicFeedbackAssignment;
use App\Models\PublicFeedbackCategory;
use App\Models\PublicFeedbackReportExport;
use App\Models\PublicFeedbackStatusHistory;
use App\Models\User;
use App\Services\ServiceFeedback\PublicFeedbackWorkflow;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
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
    'service_feedback.view', 'service_feedback.complaints.view', 'service_feedback.view_organization_target',
    'service_feedback.view_employee_target', 'service_feedback.view_sensitive_attachments',
];

beforeEach(function (): void {
    Storage::fake('local');
    app()->setLocale('en');
    $this->withHeader('X-Locale', 'en');
    $this->s = SituationRoomScenario::build();
    foreach ([...ADMIN_ALL, 'service_feedback.view_submitter_identity', 'service_feedback.review', 'service_feedback.complaints.review',
        'service_feedback.resolve', 'service_feedback.close', 'service_feedback.assign', 'service_feedback.reassign', 'service_feedback.hide', 'service_feedback.export'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    // Only the rows this test makes: the scenario's own complaints are left out of every count below.
    EmployeeServiceFeedback::query()->delete();

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

function apfDetailUrl(EmployeeServiceFeedback $row): string
{
    return $row->type->value === 'feedback'
        ? route('service-feedback.admin.show', $row->id)
        : route('service-submissions.show', ['type' => $row->type->value, 'submission' => $row->id]);
}

// ── Scope: roll-up and sibling isolation ─────────────────────────────────

it('shows Woreda A1\'s Feedback, Tip-off and Complaint to the woreda, its sub-city and its bureau, and to no sibling', function (string $orgKey, int $expected): void {
    $viewer = apfViewer($orgKey);
    $props = apfProps($viewer, route('service-submissions.index'));

    expect($props['entries']['total'])->toBe($expected)
        ->and($props['overview']['total'])->toBe($expected);

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

    $props = apfProps(apfViewer('subcity_a'), route('service-submissions.index', ['target_type' => 'employee']));
    expect($props['entries']['total'])->toBe(1)
        ->and($props['entries']['data'][0]['target_type'])->toBe('employee')
        ->and($props['entries']['data'][0]['target_name'])->toBe($employee->full_name);

    expect(apfProps(apfViewer('woreda_a2'), route('service-submissions.index', ['target_type' => 'employee']))['entries']['total'])->toBe(0);

    // Without the employee-target grant the row is neither listed nor opened.
    $orgOnly = $this->s->viewer(['service_feedback.view', 'service_feedback.complaints.view', 'service_feedback.view_organization_target'], 'bureau');
    expect(apfProps($orgOnly, route('service-submissions.index'))['entries']['total'])->toBe(3);
    $this->actingAs($orgOnly)->get(apfDetailUrl($row))->assertForbidden();
});

it('keeps Complaints from a user who holds the general grant but not the Complaint grant — on the list, the cards and the export', function (): void {
    $general = $this->s->viewer(['service_feedback.view', 'service_feedback.view_organization_target', 'service_feedback.export'], 'bureau');

    $props = apfProps($general, route('service-submissions.index'));
    expect($props['entries']['total'])->toBe(2)
        ->and($props['overview']['by_type']['complaint'])->toBe(0)
        ->and($props['filterOptions']['types'])->toBe(['feedback', 'suggestion']);

    $this->actingAs($general)->post(route('service-feedback.submission-reports.export'), ['format' => 'csv'])->assertRedirect();
    $csv = Storage::disk('local')->get(PublicFeedbackReportExport::query()->sole()->path);
    expect($csv)->not->toContain($this->rows['complaint']->reference_number)
        ->and($csv)->toContain($this->rows['suggestion']->reference_number);

    $this->actingAs($general)->get(apfDetailUrl($this->rows['complaint']))->assertForbidden();
});

it('returns nothing for forged organization, unit, category and handler filters', function (array $query): void {
    $props = apfProps(apfViewer('subcity_a'), route('service-submissions.index', $query));

    expect($props['entries']['total'])->toBe(0)->and($props['overview']['total'])->toBe(0);
})->with([
    'a sibling organization' => [fn () => ['organization_id' => test()->s->org['subcity_b']->id]],
    'the parent organization' => [fn () => ['organization_id' => test()->s->org['bureau']->id]],
    'an unknown unit' => [fn () => ['organization_unit_id' => (string) Str::uuid()]],
    'an unknown category' => [fn () => ['category_id' => (string) Str::uuid()]],
    'an unknown handler' => [fn () => ['handler_type' => 'user', 'handler_id' => '999999']],
]);

// ── List, filters, counts, unrouted queue ───────────────────────────────

it('lists every type with its target, organization and status, and none of the submitter or the narrative', function (): void {
    $props = apfProps(apfViewer('bureau', ['service_feedback.view_submitter_identity']), route('service-submissions.index'));
    $rows = collect($props['entries']['data'])->keyBy('type');

    expect($rows->keys()->sort()->values()->all())->toBe(['complaint', 'feedback', 'suggestion'])
        ->and($rows['complaint']['target_type'])->toBe('organization')
        ->and($rows['complaint']['target_name'])->toBe('Woreda A1')
        ->and($rows['complaint']['organization'])->toBe('Woreda A1')
        ->and($rows['complaint']['status'])->toBe('unrouted')
        ->and($rows['complaint']['url'])->toBe(apfDetailUrl($this->rows['complaint']))
        ->and($rows['feedback']['url'])->toBe(apfDetailUrl($this->rows['feedback']));

    // Even with the identity grant, the list carries no submitter and no narrative.
    expect(json_encode($props['entries']))->not->toContain('Private Submitter')
        ->not->toContain('+251911000000')
        ->not->toContain('Private narrative');
});

it('filters by type, target, category, status and identity, and reconciles the cards with the list', function (): void {
    $category = PublicFeedbackCategory::query()->create(['code' => 'WAIT', 'name_en' => 'Waiting time', 'name_am' => 'የጥበቃ ጊዜ', 'submission_types' => ['complaint'], 'target_types' => ['organization'], 'is_active' => true]);
    $this->rows['complaint']->forceFill(['category_id' => $category->id])->save();
    $viewer = apfViewer('subcity_a');

    expect(apfProps($viewer, route('service-submissions.index', ['submission_type' => 'complaint']))['entries']['total'])->toBe(1)
        ->and(apfProps($viewer, route('service-submissions.index', ['target_type' => 'organization']))['entries']['total'])->toBe(3)
        ->and(apfProps($viewer, route('service-submissions.index', ['category_id' => $category->id]))['entries']['data'][0]['category'])->toBe('Waiting time')
        ->and(apfProps($viewer, route('service-submissions.index', ['status' => 'pending']))['entries']['total'])->toBe(1)
        ->and(apfProps($viewer, route('service-submissions.index', ['identity_mode' => 'anonymous']))['entries']['data'][0]['type'])->toBe('suggestion');

    $props = apfProps($viewer, route('service-submissions.index', ['submission_type' => 'complaint']));
    // The cards ignore the type filter; their parts add up to the whole, which matches the database.
    $inScope = EmployeeServiceFeedback::query()->whereIn('organization_id', [$this->s->org['subcity_a']->id, $this->s->org['woreda_a1']->id, $this->s->org['woreda_a2']->id])->count();
    expect(array_sum($props['overview']['by_type']))->toBe($props['overview']['total'])
        ->and($props['overview']['total'])->toBe($inScope)
        ->and(array_sum($props['overview']['by_target']))->toBe($inScope);
});

it('finds a submission by its exact reference and by the institution\'s name', function (): void {
    $viewer = apfViewer('bureau');
    $reference = $this->rows['complaint']->reference_number;

    expect(collect(apfProps($viewer, route('service-submissions.index', ['q' => strtolower($reference)]))['entries']['data'])->pluck('id')->all())->toBe([$this->rows['complaint']->id])
        ->and(apfProps($viewer, route('service-submissions.index', ['q' => 'Woreda A1']))['entries']['total'])->toBe(3)
        ->and(apfProps($viewer, route('service-submissions.index', ['q' => 'Private narrative']))['entries']['total'])->toBe(0);
});

it('keeps unrouted Tip-offs and Complaints in their own queue until a handler is assigned', function (): void {
    $viewer = apfViewer('subcity_a', ['service_feedback.assign']);
    $props = apfProps($viewer, route('service-submissions.unrouted'));

    expect($props['queue'])->toBe('unrouted')
        ->and(collect($props['entries']['data'])->pluck('type')->sort()->values()->all())->toBe(['complaint', 'suggestion'])
        ->and($props['overview']['by_status']['unrouted'])->toBe(2);

    // Assigning moves it out of the queue; the institution it is about never changes.
    $this->actingAs($viewer)->post(route('service-submissions.assign', ['type' => 'complaint', 'submission' => $this->rows['complaint']->id]), [
        'handler_type' => 'user', 'handler_id' => (string) $viewer->id, 'reason' => 'Taking this one',
    ])->assertSessionHasNoErrors();

    $complaint = $this->rows['complaint']->fresh();
    expect($complaint->status)->toBe(ServiceFeedbackStatus::Assigned)
        ->and($complaint->organization_target_id)->toBe($this->s->org['woreda_a1']->id)
        ->and($complaint->organization_id)->toBe($this->s->org['woreda_a1']->id)
        ->and(PublicFeedbackAssignment::query()->where('submission_id', $complaint->id)->whereNull('ended_at')->count())->toBe(1)
        ->and(apfProps($viewer, route('service-submissions.unrouted'))['entries']['total'])->toBe(1);
});

// ── Actions: scope and workflow ─────────────────────────────────────────

it('refuses a sibling every action and every file on a submission it cannot see', function (): void {
    $complaint = $this->rows['complaint'];
    Storage::disk('local')->put('service-feedback/'.$complaint->id.'/e.png', 'png-bytes');
    $file = $complaint->attachments()->create(['disk' => 'local', 'path' => 'service-feedback/'.$complaint->id.'/e.png', 'original_name' => 'e.png',
        'mime_type' => 'image/png', 'size_bytes' => 9, 'sha256' => hash('sha256', 'png-bytes'), 'scan_status' => 'not_scanned']);
    $sibling = apfViewer('woreda_a2', ['service_feedback.complaints.review', 'service_feedback.assign', 'service_feedback.review', 'service_feedback.hide']);
    $params = ['type' => 'complaint', 'submission' => $complaint->id];

    $this->actingAs($sibling)->get(route('service-submissions.attachments.download', $params + ['attachment' => $file->id]))->assertForbidden();
    $this->actingAs($sibling)->post(route('service-submissions.assign', $params), ['handler_type' => 'user', 'handler_id' => (string) $sibling->id])->assertForbidden();
    $this->actingAs($sibling)->post(route('service-submissions.transition', $params), ['status' => 'assigned'])->assertForbidden();
    $this->actingAs($sibling)->post(route('service-feedback.admin.review', $this->rows['feedback']), ['status' => 'reviewed'])->assertForbidden();
    $this->actingAs($sibling)->post(route('service-feedback.admin.hide', $this->rows['feedback']))->assertForbidden();

    // The parent with the evidence grant downloads it; a file of another submission, or none at all, is not found.
    $parent = apfViewer('subcity_a');
    $this->actingAs($parent)->get(route('service-submissions.attachments.download', $params + ['attachment' => $file->id]))->assertOk();
    $this->actingAs($parent)->get(route('service-submissions.attachments.download', ['type' => 'complaint', 'submission' => $this->rows['suggestion']->id, 'attachment' => $file->id]))->assertNotFound();
    $this->actingAs($parent)->get(route('service-submissions.attachments.download', $params + ['attachment' => (string) Str::uuid()]))->assertNotFound();

    expect($complaint->fresh()->status)->toBe(ServiceFeedbackStatus::Unrouted)
        ->and($this->rows['feedback']->fresh()->status)->toBe(ServiceFeedbackStatus::Pending);
});

it('moves a Feedback only along its workflow, recording every step, and closes it with the close grant only', function (): void {
    $feedback = $this->rows['feedback'];
    $reviewer = apfViewer('subcity_a', ['service_feedback.review']);

    $this->actingAs($reviewer)->post(route('service-feedback.admin.review', $feedback), ['status' => 'reviewed', 'review_note' => 'Read and shared with the office.'])->assertSessionHasNoErrors();
    $this->actingAs($reviewer)->post(route('service-feedback.admin.review', $feedback), ['status' => 'resolved'])->assertSessionHasNoErrors();
    // Resolved cannot go back to reviewed.
    $this->actingAs($reviewer)->post(route('service-feedback.admin.review', $feedback), ['status' => 'reviewed'])->assertSessionHasErrors('status');
    // Closing is the close grant, not the review grant.
    $this->actingAs($reviewer)->post(route('service-feedback.admin.review', $feedback), ['status' => 'closed'])->assertForbidden();
    $this->actingAs(apfViewer('subcity_a', ['service_feedback.review', 'service_feedback.close']))
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
    $moderator = apfViewer('subcity_a', ['service_feedback.review', 'service_feedback.hide']);
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
    $handler = apfViewer('bureau', ['service_feedback.assign', 'service_feedback.review', 'service_feedback.complaints.review', 'service_feedback.resolve', 'service_feedback.close']);
    $params = ['type' => $type, 'submission' => $row->id];

    // Skipping straight to the outcome is refused.
    $this->actingAs($handler)->post(route('service-submissions.transition', $params), ['status' => 'closed'])->assertSessionHasErrors('status');

    $this->actingAs($handler)->post(route('service-submissions.assign', $params), ['handler_type' => 'user', 'handler_id' => (string) $handler->id])->assertSessionHasNoErrors();
    foreach ($steps as $status) {
        $this->actingAs($handler)->post(route('service-submissions.transition', $params), ['status' => $status, 'reason' => 'Step '.$status])->assertSessionHasNoErrors();
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
        ->post(route('service-feedback.submission-reports.export'), ['format' => 'csv', 'submission_type' => 'complaint'])->assertRedirect();

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
