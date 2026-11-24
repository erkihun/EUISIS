<?php

declare(strict_types=1);

use App\Backbone\Contracts\AccessScopeReader;
use App\Backbone\Contracts\AuditTrail;
use App\Backbone\Contracts\OrganizationLookup;
use App\Backbone\Contracts\UserNotifier;
use App\Backbone\Contracts\WorkingDays;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\UserOrganizationScope;
use App\Services\OrganizationScope\OrganizationScopeService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Reporting\Application\Ports\OrganizationDirectory;
use Modules\Reporting\Application\Ports\WorkingCalendar;
use Modules\Reporting\Application\Support\ReportingSettings;
use Modules\Reporting\Application\UseCases\PrepareReport;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Modules\Reporting\Domain\Enums\HolidayAdjustment;
use Modules\Reporting\Infrastructure\Notifications\ReportingNotification;
use Modules\Reporting\Infrastructure\Persistence\Models\Artifact;
use Modules\Reporting\Infrastructure\Persistence\Models\Attachment;
use Modules\Reporting\Infrastructure\Persistence\Models\Cycle;
use Modules\Reporting\Infrastructure\Persistence\Models\Obligation;
use Modules\Reporting\Infrastructure\Persistence\Models\ReportType;
use Modules\Reporting\Infrastructure\Persistence\Models\Submission;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\Support\ReportingScenario;

/*
 * The pages, files and exports over HTTP: scope is enforced on every route,
 * a client-supplied organization id is never trusted, and the integration
 * adapters keep their contracts with the Core.
 */

beforeEach(function (): void {
    Storage::fake('local');
    ReportingScenario::clock('2026-11-10 09:00');
    $this->s = ReportingScenario::build()->configure();
    $this->cycle = $this->s->publish();
    $this->obligation = $this->s->obligation($this->cycle, 'A');
});

function uploadedDocx(string $name = 'report.docx'): UploadedFile
{
    $word = new PhpWord;
    $word->addSection()->addText('Report body');
    $path = tempnam(sys_get_temp_dir(), 'rpt-http').'.docx';
    IOFactory::createWriter($word, 'Word2007')->save($path);

    return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
}

it('opens every page for the people it is meant for', function (): void {
    $officer = $this->s->officer('A');
    $submission = $this->s->writeAndSubmit($this->obligation, $officer);
    $reviewer = $this->s->reviewer();

    $this->actingAs($this->s->admin)->get(route('reporting.overview'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('modules/reporting/Overview', false)->where('summary.counts.expected', 3)->where('role.oversight', true));
    foreach (['reporting.register', 'reporting.archive', 'reporting.calendar', 'reporting.types.index', 'reporting.types.create', 'reporting.schedules.index', 'reporting.schedules.create', 'reporting.consolidations.index', 'reporting.follow-ups.index', 'reporting.settings.edit', 'reporting.assignments'] as $name) {
        $this->actingAs($this->s->admin)->get(route($name))->assertOk();
    }
    $this->actingAs($this->s->admin)->get(route('reporting.register', ['view' => 'matrix', 'report_type_id' => $this->s->type->id]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('view', 'matrix')->has('matrix.cycles', 1));
    $this->actingAs($this->s->admin)->get(route('reporting.types.edit', $this->s->type->id))->assertOk();
    $this->actingAs($this->s->admin)->get(route('reporting.schedules.edit', $this->s->schedule->id))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('targets', 3));

    $this->actingAs($officer)->get(route('reporting.assignments', ['view' => 'waiting']))->assertOk()->assertInertia(fn (Assert $page) => $page->has('rows.data', 1));
    $this->actingAs($officer)->get(route('reporting.obligations.show', $this->obligation->id))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('modules/reporting/ObligationShow', false)->where('submissions.0.reference_no', $submission->reference_no)->where('can.review', false));
    // Submitted: the editor redirects to the report's page.
    $this->actingAs($officer)->get(route('reporting.prepare.edit', $this->obligation->id))->assertRedirect(route('reporting.obligations.show', $this->obligation->id));
    $this->actingAs($this->s->officer('B'))->get(route('reporting.prepare.edit', $this->s->obligation($this->cycle, 'B')->id))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('modules/reporting/Prepare', false)->has('sections', 3)->where('draft.lock_version', 0));

    $this->actingAs($reviewer)->get(route('reporting.review.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('rows.data', 1));
    $this->actingAs($reviewer)->get(route('reporting.review.show', $submission->id))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can.decide', true)->where('obligation.workflow', 'under_review')->has('submission.sections', 3));
});

it('denies pages, files, counts and exports across organization scope', function (): void {
    $officer = $this->s->officer('A');
    $submission = $this->s->writeAndSubmit($this->obligation, $officer);
    $file = Attachment::query()->create([
        'obligation_id' => $this->obligation->id, 'version_no' => 1, 'submission_id' => $submission->id, 'kind' => 'annex', 'original_name' => 'annex.pdf',
        'disk' => 'local', 'path' => 'reporting/test/annex.pdf', 'mime' => 'application/pdf', 'size' => 9, 'sha256' => str_repeat('a', 64), 'scan_status' => 'clean', 'uploaded_by' => $officer->id,
    ]);
    Storage::disk('local')->put($file->path, '%PDF-1.4 x');
    $artifact = Artifact::query()->where('owner_id', $submission->id)->where('status', 'completed')->firstOrFail();

    // Another institution's officer holding every Reporting permission.
    $outsider = $this->s->user(ReportingRoles::ALL, ['X']);
    $this->actingAs($outsider)->get(route('reporting.obligations.show', $this->obligation->id))->assertForbidden();
    $this->actingAs($outsider)->get(route('reporting.review.show', $submission->id))->assertForbidden();
    $this->actingAs($outsider)->get(route('reporting.files.attachment', $file->id))->assertForbidden();
    $this->actingAs($outsider)->get(route('reporting.files.artifact', $artifact->id))->assertForbidden();
    $this->actingAs($outsider)->get(route('reporting.prepare.edit', $this->obligation->id))->assertForbidden();
    $this->actingAs($outsider)->get(route('reporting.prepare.template', $this->obligation->id))->assertForbidden();
    $this->actingAs($outsider)->putJson(route('reporting.prepare.save', $this->s->obligation($this->cycle, 'B')->id), ['lock_version' => 0, 'content' => ['executive_summary' => '<p>x</p>']])->assertForbidden();
    $this->actingAs($outsider)->post(route('reporting.review.decide', $submission->id), ['decision' => 'approve', 'step_no' => 1])->assertForbidden();
    $this->actingAs($outsider)->post(route('reporting.obligations.applicability', $this->obligation->id), ['applicability' => 'exempted', 'reason' => 'x'])->assertForbidden();
    // Counts: an organization id in the query string does not widen the scope.
    $this->actingAs($outsider)->get(route('reporting.overview'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('summary.counts.expected', 0));
    $this->actingAs($outsider)->get(route('reporting.register', ['organization_id' => $this->s->org['A']->id]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('rows.total', 0)->where('summary.counts.expected', 0));
    $export = $this->actingAs($outsider)->get(route('reporting.register.export', ['organization_id' => $this->s->org['A']->id]))->assertOk()->streamedContent();
    expect(substr_count(trim($export), "\n"))->toBe(0);

    // No Reporting permission at all, though in the right institution.
    $plain = $this->s->user(['dashboard.view'], ['A']);
    $this->actingAs($plain)->get(route('reporting.overview'))->assertForbidden();
    $this->actingAs($plain)->get(route('reporting.files.artifact', $artifact->id))->assertForbidden();
    // View without the export permission.
    $this->actingAs($this->s->preparer('A'))->get(route('reporting.register.export'))->assertForbidden();
    // Configuration needs its own permissions.
    $this->actingAs($officer)->get(route('reporting.types.index'))->assertForbidden();
    $this->actingAs($officer)->get(route('reporting.schedules.index'))->assertForbidden();
    $this->actingAs($officer)->get(route('reporting.settings.edit'))->assertForbidden();
    $this->actingAs($officer)->put(route('reporting.settings.update'), ['maintenance' => true])->assertForbidden();

    // The people entitled to them get the files, and each download is audited.
    $this->actingAs($officer)->get(route('reporting.files.attachment', $file->id))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->actingAs($this->s->reviewer())->get(route('reporting.files.artifact', $artifact->id))->assertOk();
    expect(AuditLog::query()->where('event_type', 'reporting.file_downloaded')->count())->toBe(2);

    // Access is rechecked at download: the reviewer's scope is withdrawn, the same link stops working.
    $former = $this->s->reviewer();
    $this->actingAs($former)->get(route('reporting.files.artifact', $artifact->id))->assertOk();
    // (The reviewer is moved to another institution.)
    UserOrganizationScope::query()->where('user_id', $former->id)->update(['organization_id' => $this->s->org['X']->id]);
    $former->forceFill(['default_organization_id' => $this->s->org['X']->id])->save();
    app(OrganizationScopeService::class)->clearCache();
    app()->forgetScopedInstances();
    $this->actingAs($former->fresh())->get(route('reporting.files.artifact', $artifact->id))->assertForbidden();
});

it('keeps a confidential report\'s narrative from people who only see the register', function (): void {
    ReportType::query()->whereKey($this->s->type->id)->update(['confidentiality' => 'confidential']);
    $submission = $this->s->writeAndSubmit($this->obligation, $this->s->officer('A'));
    // Sees the receiving bureau's register, but holds neither a review permission nor the confidential grant.
    $viewer = $this->s->user([ReportingRoles::VIEW], ['HQ']);

    $this->actingAs($viewer)->get(route('reporting.obligations.show', $this->obligation->id))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('content_visible', false)->missing('submissions.0.sections')->where('submissions.0.attachments', []));
    $this->actingAs($viewer)->get(route('reporting.review.show', $submission->id))->assertForbidden();
    $this->actingAs($viewer)->get(route('reporting.files.artifact', Artifact::query()->where('owner_id', $submission->id)->value('id')))->assertForbidden();
    $this->actingAs($this->s->user([ReportingRoles::VIEW, ReportingRoles::VIEW_CONFIDENTIAL], ['HQ']))->get(route('reporting.review.show', $submission->id))->assertOk();
});

it('autosaves over JSON, reports a conflict, and inspects uploads', function (): void {
    $officer = $this->s->officer('A');
    $url = route('reporting.prepare.save', $this->obligation->id);

    $this->actingAs($officer)->putJson($url, ['lock_version' => 0, 'content' => ['executive_summary' => '<p>First</p>']])->assertOk()->assertJson(['lock_version' => 1]);
    // A second tab still holding lock version 0.
    $this->actingAs($officer)->putJson($url, ['lock_version' => 0, 'content' => ['executive_summary' => '<p>Overwrite</p>']])->assertStatus(409)->assertJson(['lock_version' => 1]);
    $this->actingAs($officer)->putJson($url, ['content' => ['executive_summary' => '<p>No lock</p>']])->assertStatus(422);

    $upload = route('reporting.prepare.upload', $this->obligation->id);
    $bad = UploadedFile::fake()->createWithContent('report.docx', 'not a word file');
    $this->actingAs($officer)->post($upload, ['file' => $bad, 'kind' => 'report_document'], ['Accept' => 'application/json'])->assertStatus(422)->assertJson(['reason' => 'not_docx']);
    $response = $this->actingAs($officer)->post($upload, ['file' => uploadedDocx('ሪፖርት.docx'), 'kind' => 'report_document'], ['Accept' => 'application/json'])->assertCreated();
    $id = $response->json('attachment.id');

    expect(Attachment::query()->whereKey($id)->value('original_name'))->toBe('ሪፖርት.docx')
        ->and(Storage::disk('local')->exists(Attachment::query()->whereKey($id)->value('path')))->toBeTrue()
        // Stored under a generated name on the private disk, never the client's name.
        ->and(Attachment::query()->whereKey($id)->value('path'))->not->toContain('ሪፖርት');

    // A draft file belongs to the reporting side: the receiving bureau cannot fetch it before submission.
    $this->actingAs($this->s->reviewer())->get(route('reporting.files.attachment', $id))->assertForbidden();
    $this->actingAs($officer)->get(route('reporting.files.attachment', $id))->assertOk();
    $this->actingAs($officer)->deleteJson(route('reporting.prepare.remove', $id))->assertOk();
    expect(Attachment::query()->whereKey($id)->exists())->toBeFalse();

    // The template and the draft come down as Word files.
    $this->actingAs($officer)->get(route('reporting.prepare.template', $this->obligation->id))->assertOk()->assertDownload('report-template.docx');
    $this->actingAs($officer)->get(route('reporting.prepare.draft-document', $this->obligation->id))->assertOk()->assertDownload('report-draft.docx');
});

it('submits and reviews through the pages, with messages in the reader\'s language', function (): void {
    $officer = $this->s->officer('A');
    $this->s->write($this->obligation, $officer);
    $lock = app(PrepareReport::class)->draft($this->obligation->fresh())->lock_version;

    $this->actingAs($officer)->post(route('reporting.prepare.submit', $this->obligation->id), ['lock_version' => $lock])
        ->assertRedirect(route('reporting.obligations.show', $this->obligation->id))->assertSessionHas('success');
    $submission = Submission::query()->firstOrFail();

    $reviewer = $this->s->reviewer();
    // Returning without a reason is refused with a readable message, not an error page.
    $this->actingAs($reviewer)->from(route('reporting.review.show', $submission->id))->post(route('reporting.review.decide', $submission->id), ['decision' => 'return', 'step_no' => 1])
        ->assertRedirect(route('reporting.review.show', $submission->id))->assertSessionHasErrors(['workflow' => 'A reason is required.']);
    $this->actingAs($reviewer)->post(route('reporting.review.decide', $submission->id), ['decision' => 'approve', 'step_no' => 1])->assertRedirect(route('reporting.review.index'));
    // The same step again (a double click): refused, nothing decided twice.
    $this->actingAs($reviewer)->from(route('reporting.review.index'))->post(route('reporting.review.decide', $submission->id), ['decision' => 'approve', 'step_no' => 1])->assertSessionHasErrors('workflow');
    $this->actingAs($this->s->approver())->post(route('reporting.review.decide', $submission->id), ['decision' => 'approve', 'step_no' => 2])->assertSessionHasNoErrors();

    expect($this->obligation->fresh()->workflow_status->value)->toBe('approved')
        ->and(AuditLog::query()->where('event_type', 'reporting.submitted')->count())->toBe(1)
        ->and(AuditLog::query()->where('event_type', 'reporting.review_decided')->count())->toBe(2);
});

it('exports the register as CSV and Excel with formulas neutralised', function (): void {
    // A hostile institution name, as it stood when the cycle was published.
    Obligation::query()->whereKey($this->obligation->id)->update(['organization_name_en' => '=HYPERLINK("http://attacker.example","Click")', 'accountable_name' => '@SUM(A1:A9)']);
    $this->s->writeAndSubmit($this->s->obligation($this->cycle, 'B'), $this->s->officer('B'));
    ReportingScenario::clock('2026-11-20 09:00');

    $csv = $this->actingAs($this->s->admin)->get(route('reporting.register.export', ['cycle_id' => $this->cycle->id]))->assertOk()->streamedContent();
    $lines = array_map('str_getcsv', array_filter(explode("\n", trim(substr($csv, 3)))));

    expect($lines)->toHaveCount(4)
        ->and($lines[0][1])->toBe('Institution')->and($lines[0][2])->toBe('Work unit')
        ->and(collect($lines)->firstWhere(0, 'RPT-A')[1])->toBe('\'=HYPERLINK("http://attacker.example","Click")')
        ->and(collect($lines)->firstWhere(0, 'RPT-A')[3])->toBe("'@SUM(A1:A9)")
        ->and(collect($lines)->firstWhere(0, 'RPT-A')[10])->toBe('Overdue, not submitted')
        ->and(collect($lines)->firstWhere(0, 'RPT-B')[10])->toBe('On time')
        ->and(collect($lines)->firstWhere(0, 'RPT-B')[6])->toBe('2026-11-14 17:30 EAT')
        ->and(AuditLog::query()->where('event_type', 'reporting.exported')->count())->toBe(1);

    $response = $this->actingAs($this->s->admin)->get(route('reporting.register.export', ['cycle_id' => $this->cycle->id, 'format' => 'xlsx']))->assertOk();
    $sheet = PhpOffice\PhpSpreadsheet\IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet();
    $cell = $sheet->getCell('B2');

    // Text, not a formula.
    expect($sheet->getHighestRow())->toBe(4)
        ->and($cell->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($cell->getValue())->toStartWith('=HYPERLINK');
});

it('switches off with the feature flag and pauses writes with the maintenance switch', function (): void {
    $officer = $this->s->officer('A');
    app(ReportingSettings::class)->put('maintenance', true, $this->s->admin->id);
    app()->forgetScopedInstances();

    $this->actingAs($officer)->putJson(route('reporting.prepare.save', $this->obligation->id), ['lock_version' => 0, 'content' => ['executive_summary' => '<p>x</p>']])->assertStatus(503);
    $this->actingAs($officer)->get(route('reporting.assignments'))->assertOk();
    // The settings page is how the module is switched back on.
    $this->actingAs($this->s->admin)->put(route('reporting.settings.update'), [
        'maintenance' => false, 'max_upload_kb' => 20480, 'email_notifications' => false, 'document_font' => 'Nyala',
        'reminder_rules' => ['days_before' => [7, 3, 1], 'on_due_date' => true, 'when_overdue' => true, 'escalate_after_working_days' => 3],
    ])->assertSessionHasNoErrors();
    app()->forgetScopedInstances();
    $this->actingAs($officer)->putJson(route('reporting.prepare.save', $this->obligation->id), ['lock_version' => 0, 'content' => ['executive_summary' => '<p>x</p>']])->assertOk();

    config(['features.reporting' => false]);
    $this->actingAs($this->s->admin)->get(route('reporting.overview'))->assertNotFound();
});

it('publishes a special report for chosen institutions, in business time', function (): void {
    $payload = [
        'report_type_id' => $this->s->type->id, 'period_label_en' => 'Flood response', 'period_label_am' => 'የጎርፍ ምላሽ',
        'period_start' => '2026-11-01', 'period_end' => '2026-11-15', 'opens_at' => '2026-11-10T08:00', 'due_at' => '2026-11-25T17:30', 'late_policy' => 'accept',
        'targets' => [['organization_id' => $this->s->org['A']->id], ['organization_id' => $this->s->org['B']->id]],
    ];

    $this->actingAs($this->s->admin)->post(route('reporting.schedules.special'), $payload)->assertSessionHasNoErrors();
    $cycle = Cycle::query()->whereNull('schedule_id')->firstOrFail();

    // 17:30 in Addis Ababa is 14:30 UTC.
    expect($cycle->due_at->toIso8601String())->toBe('2026-11-25T14:30:00+00:00')
        ->and($cycle->period_label_am)->toBe('የጎርፍ ምላሽ')
        ->and(Obligation::query()->where('cycle_id', $cycle->id)->count())->toBe(2);

    // Someone who may publish only for their own institution cannot name another.
    $scoped = $this->s->user([ReportingRoles::VIEW, ReportingRoles::PUBLISH_SCHEDULES], ['X']);
    $this->actingAs($scoped)->post(route('reporting.schedules.special'), $payload)->assertForbidden();
    $this->actingAs($this->s->officer('A'))->post(route('reporting.schedules.special'), $payload)->assertForbidden();
});

it('keeps the integration adapters\' contracts with the Core', function (): void {
    $officer = $this->s->officer('A');
    $scope = app(AccessScopeReader::class);
    $lookup = app(OrganizationLookup::class);

    // Scope and permissions by user id, from the one canonical source.
    expect($scope->scopeOf($officer->id)->unrestricted)->toBeFalse()
        ->and($scope->scopeOf($officer->id)->organizationUuids)->toBe([$this->s->org['A']->id])
        ->and($scope->scopeOf($this->s->admin->id)->unrestricted)->toBeTrue()
        ->and($scope->scopeOf(999999)->organizationUuids)->toBe([])
        ->and($scope->grantedAmong($officer->id, ReportingRoles::ALL))->toEqualCanonicalizing(ReportingRoles::OFFICER)
        ->and($scope->user($officer->id)->name)->toBe($officer->name)
        ->and(array_keys($scope->users([$officer->id, 999999])))->toBe([$officer->id])
        ->and(array_map(fn ($u) => $u->userId, $scope->holdersFor(ReportingRoles::SUBMIT, $this->s->org['A']->id)))->toContain($officer->id)
        ->and(array_map(fn ($u) => $u->userId, $scope->holdersFor(ReportingRoles::SUBMIT, $this->s->org['B']->id)))->not->toContain($officer->id)
        ->and($scope->searchUsers('Reporting User', [$this->s->org['X']->id]))->toBe([]);

    // Organizations in batches, never wider than the set given.
    expect(array_keys($lookup->findMany([$this->s->org['A']->id, 'not-a-uuid', '00000000-0000-4000-8000-000000000000'])))->toBe([$this->s->org['A']->id])
        ->and(array_map(fn ($o) => $o->code, $lookup->search('Bureau', [$this->s->org['A']->id, $this->s->org['B']->id])))->toEqualCanonicalizing(['RPT-A', 'RPT-B'])
        ->and($lookup->page([$this->s->org['A']->id], 1, 10)['total'])->toBe(1);

    // The module's ports, through the adapters.
    $directory = app(OrganizationDirectory::class);
    Organization::query()->whereKey($this->s->org['C']->id)->update(['status' => 'inactive']);
    expect($directory->find($this->s->org['A']->id)->nameAm)->toBe('ንግድ ቢሮ')
        ->and($directory->find('00000000-0000-4000-8000-000000000000'))->toBeNull()
        ->and($directory->hierarchySnapshot($this->s->org['A']->id))->toHaveKeys(['version', 'ancestors'])
        ->and($directory->subtreeIds($this->s->org['A']->id))->toContain($this->s->org['A']->id)
        ->and(array_map(fn ($o) => $o->code, $directory->search($this->s->actor($officer), 'Bureau')))->toBe(['RPT-A'])
        ->and($directory->page($this->s->actor($this->s->admin))['total'])->toBe(4);

    // Working days: a Saturday deadline moves to Monday, the time of day and the timezone are kept.
    $calendar = app(WorkingCalendar::class);
    $saturday = new DateTimeImmutable('2026-11-14 17:30:00', new DateTimeZone('Africa/Addis_Ababa'));
    expect($calendar->adjust($saturday, HolidayAdjustment::NextWorkingDay)->format('Y-m-d H:i T'))->toBe('2026-11-16 17:30 EAT')
        ->and($calendar->adjust($saturday, HolidayAdjustment::PreviousWorkingDay)->format('Y-m-d H:i'))->toBe('2026-11-13 17:30')
        ->and($calendar->adjust($saturday, HolidayAdjustment::None))->toEqual($saturday)
        ->and($calendar->addWorkingDays($saturday, 3)->format('Y-m-d H:i'))->toBe('2026-11-18 17:30')
        ->and(app(WorkingDays::class)->isWorkingDay($saturday))->toBeFalse();

    // Audit and notification facades.
    app(AuditTrail::class)->record('reporting.settings_changed', $officer->id, 'reporting.settings', 'settings', $this->s->org['A']->id, null, ['password' => 'secret', 'note' => 'ok'], 'Why');
    $entry = AuditLog::query()->where('event_type', 'reporting.settings_changed')->latest('created_at')->firstOrFail();
    expect($entry->actor_user_id)->toBe($officer->id)
        ->and($entry->new_values['subject_type'])->toBe('reporting.settings')
        ->and($entry->new_values['password'])->toBe('[REDACTED]')
        ->and($entry->reason)->toBe('Why')
        ->and(app(UserNotifier::class)->notify([$officer->id, 999999], new ReportingNotification('workflow_approved', ['type' => 'T', 'period' => 'P', 'reference' => 'R'], '/reporting')))->toBe([$officer->id])
        ->and($officer->notifications()->count())->toBe(1);
});
