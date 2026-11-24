<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Modules\Reporting\Application\Data\ReportDocument;
use Modules\Reporting\Application\Ports\DocumentRenderer;
use Modules\Reporting\Application\Queries\ComplianceQuery;
use Modules\Reporting\Application\Support\ReportingAccessDenied;
use Modules\Reporting\Application\Support\StaleVersion;
use Modules\Reporting\Application\UseCases\PrepareReport;
use Modules\Reporting\Application\UseCases\ReviewReports;
use Modules\Reporting\Application\UseCases\SubmitReport;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Modules\Reporting\Domain\Enums\ReviewDecision;
use Modules\Reporting\Domain\Enums\WorkflowStatus;
use Modules\Reporting\Domain\Workflow\WorkflowViolation;
use Modules\Reporting\Infrastructure\Documents\HtmlToWord;
use Modules\Reporting\Infrastructure\Documents\PhpWordDocumentRenderer;
use Modules\Reporting\Infrastructure\Persistence\Models\Artifact;
use Modules\Reporting\Infrastructure\Persistence\Models\Attachment;
use Modules\Reporting\Infrastructure\Persistence\Models\Comment;
use Modules\Reporting\Infrastructure\Persistence\Models\Cycle;
use Modules\Reporting\Infrastructure\Persistence\Models\Draft;
use Modules\Reporting\Infrastructure\Persistence\Models\ReviewStep;
use Modules\Reporting\Infrastructure\Persistence\Models\Submission;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\Support\ReportingScenario;

/*
 * Preparing, submitting and reviewing a report in both modes, and the rules
 * that protect the submitter's record: server time, immutability, first
 * submission kept through returns, safe retries, rendering apart from
 * submission.
 */

beforeEach(function (): void {
    Storage::fake('local');
    // Before the deadline of 14 November 17:30 EAT.
    ReportingScenario::clock('2026-11-10 09:00');
    $this->s = ReportingScenario::build()->configure();
    $this->cycle = $this->s->publish();
    $this->obligation = $this->s->obligation($this->cycle, 'A');
});

/** A real .docx written by PHPWord, as an institution would upload. */
function reportingDocx(string $text = 'የሩብ ዓመት ሪፖርት'): string
{
    $word = new PhpWord;
    $word->addSection()->addText($text);
    $path = tempnam(sys_get_temp_dir(), 'rpt-test').'.docx';
    IOFactory::createWriter($word, 'Word2007')->save($path);

    return $path;
}

it('carries a structured report from draft to approval, end to end', function (): void {
    $preparer = $this->s->preparer('A');
    $officer = $this->s->officer('A');

    $this->s->write($this->obligation, $preparer, 'ጥቅምት ወር የሥራ አፈጻጸም');
    expect($this->obligation->fresh()->workflow_status)->toBe(WorkflowStatus::Draft)
        // A preparer drafts but does not submit.
        ->and(fn () => $this->s->submit($this->obligation, $preparer))->toThrow(ReportingAccessDenied::class);

    $submission = $this->s->submit($this->obligation, $officer);
    $obligation = $this->obligation->fresh();

    expect($submission->reference_no)->toMatch('/^RPT-20261110-[A-Z0-9]{8}$/')
        ->and($submission->version_no)->toBe(1)
        ->and($submission->submitted_at->toIso8601String())->toBe('2026-11-10T06:00:00+00:00')
        ->and($submission->content['executive_summary'])->toContain('ጥቅምት ወር የሥራ አፈጻጸም')
        ->and($submission->sections_snapshot)->toHaveCount(3)
        ->and($obligation->workflow_status)->toBe(WorkflowStatus::Submitted)
        ->and($obligation->first_submission_id)->toBe($submission->id)
        ->and($obligation->review_due_at)->not->toBeNull()
        ->and(ReviewStep::query()->where('submission_id', $submission->id)->orderBy('step_no')->pluck('status')->all())->toBe(['pending', 'waiting']);

    // The Word file was generated after the commit, from the snapshot.
    $artifact = Artifact::query()->where('owner_id', $submission->id)->where('label', 'submitted')->where('format', 'docx')->firstOrFail();
    expect($artifact->status)->toBe('completed')
        ->and(Storage::disk('local')->exists($artifact->path))->toBeTrue();

    // Review, then approval, by two people of the receiving bureau.
    $reviews = app(ReviewReports::class);
    $reviewer = $this->s->reviewer();
    $reviews->begin($this->s->actor($reviewer), $submission->id);
    expect($this->obligation->fresh()->workflow_status)->toBe(WorkflowStatus::UnderReview)
        // A reviewer cannot take the approval step.
        ->and($this->s->decide($submission, $reviewer, ReviewDecision::Approve)->status)->toBe('under_review')
        ->and(fn () => $this->s->decide($submission, $reviewer, ReviewDecision::Approve))->toThrow(ReportingAccessDenied::class);

    $this->s->decide($submission, $this->s->approver(), ReviewDecision::Approve);
    $obligation = $this->obligation->fresh();

    expect($obligation->workflow_status)->toBe(WorkflowStatus::Approved)
        ->and($submission->fresh()->status)->toBe('approved')
        ->and($obligation->review_due_at)->toBeNull()
        ->and(Artifact::query()->where('owner_id', $submission->id)->where('label', 'approved')->value('status'))->toBe('completed');
});

it('carries an uploaded Word report end to end and keeps the original file', function (): void {
    $officer = $this->s->officer('A');
    $prepare = app(PrepareReport::class);
    $path = reportingDocx();
    $hash = hash_file('sha256', $path);

    $attachment = $prepare->attach($this->s->actor($officer), $this->obligation->id, $path, 'ሪፖርት.docx', 'report_document');
    $draft = $prepare->draft($this->obligation->fresh());

    // An uploaded file alone is not a submission, and not yet submittable.
    expect($this->obligation->fresh()->first_submitted_at)->toBeNull()
        ->and(fn () => app(SubmitReport::class)->submit($this->s->actor($officer), $this->obligation->id, $draft->lock_version))->toThrow(WorkflowViolation::class);

    $prepare->save($this->s->actor($officer), $this->obligation->id, ['mode' => 'upload', 'summary' => 'Summary of the month'], $draft->lock_version);
    expect(fn () => $this->s->submit($this->obligation, $officer))->toThrow(WorkflowViolation::class, 'completeness_declaration_required');

    $prepare->save($this->s->actor($officer), $this->obligation->id, ['completeness_declared' => true], $prepare->draft($this->obligation->fresh())->lock_version);
    $submission = $this->s->submit($this->obligation, $officer);

    $kept = Attachment::query()->findOrFail($attachment->id);
    expect($submission->mode)->toBe('upload')
        ->and($submission->completeness_declared)->toBeTrue()
        ->and($kept->submission_id)->toBe($submission->id)
        ->and($kept->original_name)->toBe('ሪፖርት.docx')
        // The stored bytes are the uploaded bytes.
        ->and(hash('sha256', Storage::disk('local')->get($kept->path)))->toBe($hash)
        ->and($kept->sha256)->toBe($hash);

    // Same deadlines, receipt and review as the structured mode.
    $this->s->approve($submission);
    expect($this->obligation->fresh()->workflow_status)->toBe(WorkflowStatus::Approved)
        // A file of a submitted version cannot be removed.
        ->and(fn () => $prepare->removeAttachment($this->s->actor($officer), $kept->id))->toThrow(WorkflowViolation::class, 'attachment_submitted');
});

it('treats a submission at the exact deadline as on time and one second later as late', function (): void {
    $officer = $this->s->officer('A');
    $this->s->write($this->obligation, $officer);
    $this->s->write($this->s->obligation($this->cycle, 'B'), $officerB = $this->s->officer('B'));

    ReportingScenario::clock('2026-11-14 17:30:00');
    $onTime = $this->s->submit($this->obligation, $officer);
    ReportingScenario::clock('2026-11-14 17:30:01');
    $late = $this->s->submit($this->s->obligation($this->cycle, 'B'), $officerB);

    ReportingScenario::clock('2026-11-20 09:00');
    $rows = collect(app(ComplianceQuery::class)->register($this->s->actor($this->s->admin), ['cycle_id' => $this->cycle->id])->items())->keyBy('organization.code');

    expect($onTime->is_late)->toBeFalse()
        ->and($late->is_late)->toBeTrue()
        ->and($rows['RPT-A']['timeliness'])->toBe('on_time')
        ->and($rows['RPT-B']['timeliness'])->toBe('late')
        ->and($rows['RPT-B']['days_overdue'])->toBe(1)
        ->and($rows['RPT-C']['timeliness'])->toBe('overdue');
});

it('does not let a slow review change the submitter\'s timeliness', function (): void {
    $submission = $this->s->writeAndSubmit($this->obligation, $this->s->officer('A'));

    // The receiving bureau takes six weeks.
    ReportingScenario::clock('2026-12-22 09:00');
    $query = app(ComplianceQuery::class);
    $row = collect($query->register($this->s->actor($this->s->admin), ['cycle_id' => $this->cycle->id])->items())->firstWhere('id', $this->obligation->id);

    expect($row['timeliness'])->toBe('on_time')
        ->and($row['review_overdue'])->toBeTrue()
        ->and($query->summary($this->s->actor($this->s->admin), ['cycle_id' => $this->cycle->id])['counts']['overdue_reviews'])->toBe(1);

    $this->s->approve($submission);
    $row = collect($query->register($this->s->actor($this->s->admin), ['cycle_id' => $this->cycle->id])->items())->firstWhere('id', $this->obligation->id);

    expect($row['timeliness'])->toBe('on_time')
        ->and($row['first_submitted_at'])->toBe('2026-11-10T06:00:00+00:00')
        ->and($row['workflow'])->toBe('approved');
});

it('keeps the first submission through a return and resubmission, with a new version', function (): void {
    $officer = $this->s->officer('A');
    $first = $this->s->writeAndSubmit($this->obligation, $officer);

    ReportingScenario::clock('2026-11-12 10:00');
    expect(fn () => $this->s->decide($first, $this->s->reviewer(), ReviewDecision::Return, ''))->toThrow(WorkflowViolation::class, 'reason_required');
    $this->s->decide($first, $this->s->reviewer(), ReviewDecision::Return, 'Add the plan-versus-actual table.');
    $obligation = $this->obligation->fresh();

    expect($obligation->workflow_status)->toBe(WorkflowStatus::Returned)
        ->and($obligation->current_version)->toBe(2)
        ->and($obligation->revision_due_at->toIso8601String())->toBe('2026-11-17T07:00:00+00:00')
        // The new editable version starts from the returned text.
        ->and(Draft::query()->where('obligation_id', $obligation->id)->where('version_no', 2)->firstOrFail()->content['executive_summary'])->toContain('Text')
        ->and(Comment::query()->where('obligation_id', $obligation->id)->where('visibility', 'submitter')->value('body'))->toBe('Add the plan-versus-actual table.');

    // Resubmitted after the ORIGINAL deadline: still not a late first submission.
    ReportingScenario::clock('2026-11-16 10:00');
    $this->s->write($obligation, $officer, 'Revised text');
    $second = $this->s->submit($obligation, $officer);
    $obligation = $this->obligation->fresh();

    expect($second->version_no)->toBe(2)
        ->and($second->is_late)->toBeFalse()
        ->and($obligation->first_submission_id)->toBe($first->id)
        ->and($obligation->first_submitted_at->toIso8601String())->toBe('2026-11-10T06:00:00+00:00')
        ->and($obligation->latest_submission_id)->toBe($second->id)
        ->and($obligation->revision_due_at)->toBeNull()
        ->and(Submission::query()->where('obligation_id', $obligation->id)->count())->toBe(2)
        // The returned version is untouched.
        ->and($first->fresh()->content['executive_summary'])->toContain('Text')
        ->and($first->fresh()->status)->toBe('returned');

    ReportingScenario::clock('2026-11-20 09:00');
    $row = collect(app(ComplianceQuery::class)->register($this->s->actor($this->s->admin), ['cycle_id' => $this->cycle->id])->items())->firstWhere('id', $obligation->id);
    expect($row['timeliness'])->toBe('on_time')->and($row['version'])->toBe(2);
});

it('refuses any change to a submitted version', function (): void {
    $submission = $this->s->writeAndSubmit($this->obligation, $this->s->officer('A'));

    expect(fn () => $submission->forceFill(['content' => ['executive_summary' => '<p>Changed</p>']])->save())->toThrow(LogicException::class)
        ->and(fn () => $submission->fresh()->forceFill(['submitted_at' => now()->subDays(9)])->save())->toThrow(LogicException::class)
        ->and(fn () => $submission->fresh()->delete())->toThrow(LogicException::class)
        ->and($submission->fresh()->content['executive_summary'])->toContain('Text');
});

it('survives a repeated submit request and a stale editor', function (): void {
    $officer = $this->s->officer('A');
    $this->s->write($this->obligation, $officer);
    $lock = app(PrepareReport::class)->draft($this->obligation->fresh())->lock_version;
    $submit = app(SubmitReport::class);

    $first = $submit->submit($this->s->actor($officer), $this->obligation->id, $lock);
    // The browser retries the same request (double click, lost response).
    $retry = $submit->submit($this->s->actor($officer), $this->obligation->id, $lock);

    expect($retry->id)->toBe($first->id)
        ->and(Submission::query()->where('obligation_id', $this->obligation->id)->count())->toBe(1)
        ->and(ReviewStep::query()->where('obligation_id', $this->obligation->id)->count())->toBe(2)
        ->and(Artifact::query()->where('owner_id', $first->id)->where('format', 'docx')->count())->toBe(1)
        // A colleague submitting the same version afterwards is refused, not duplicated.
        ->and(fn () => $submit->submit($this->s->actor($this->s->officer('A')), $this->obligation->id, $lock))->toThrow(WorkflowViolation::class, 'not_editable');
});

it('does not let two editors overwrite each other', function (): void {
    $one = $this->s->preparer('A');
    $two = $this->s->preparer('A');
    $prepare = app(PrepareReport::class);
    $loaded = $prepare->draft($this->obligation)->lock_version;

    $prepare->save($this->s->actor($one), $this->obligation->id, ['content' => ['executive_summary' => '<p>From the first editor</p>']], $loaded);

    expect(fn () => $prepare->save($this->s->actor($two), $this->obligation->id, ['content' => ['executive_summary' => '<p>From the second</p>']], $loaded))->toThrow(StaleVersion::class)
        ->and($prepare->draft($this->obligation->fresh())->content['executive_summary'])->toContain('first editor')
        // Submitting with the lock version of a page that is out of date is refused too.
        ->and(fn () => app(SubmitReport::class)->submit($this->s->actor($this->s->officer('A')), $this->obligation->id, $loaded))->toThrow(StaleVersion::class);
});

it('validates required sections and sanitizes the narrative', function (): void {
    $officer = $this->s->officer('A');
    $prepare = app(PrepareReport::class);
    $prepare->save($this->s->actor($officer), $this->obligation->id, ['mode' => 'structured', 'content' => [
        'executive_summary' => '<p>Fine</p><script>alert(1)</script><img src="https://attacker.example/x.png" onerror="alert(1)">',
        'achievements' => '<p>&nbsp;</p>',
        'not_a_section' => '<p>Dropped</p>',
    ]], 0);
    $draft = $prepare->draft($this->obligation->fresh());

    expect($draft->content['executive_summary'])->not->toContain('script')->not->toContain('onerror')->not->toContain('attacker.example')
        ->and($draft->content)->not->toHaveKey('not_a_section')
        ->and(fn () => $this->s->submit($this->obligation, $officer))->toThrow(WorkflowViolation::class, 'section_required');
});

it('accepts a Nil Report as a submission that is reviewed, not as an exemption', function (): void {
    $officer = $this->s->officer('A');
    $prepare = app(PrepareReport::class);
    $prepare->save($this->s->actor($officer), $this->obligation->id, ['is_nil' => true], 0);
    expect(fn () => $this->s->submit($this->obligation, $officer))->toThrow(WorkflowViolation::class, 'nil_reason_required');

    $prepare->save($this->s->actor($officer), $this->obligation->id, ['nil_reason' => 'No activity fell in this period.'], 1);
    $submission = $this->s->submit($this->obligation, $officer);
    $summary = app(ComplianceQuery::class)->summary($this->s->actor($this->s->admin), ['cycle_id' => $this->cycle->id]);

    expect($submission->is_nil)->toBeTrue()
        ->and($this->obligation->fresh()->workflow_status)->toBe(WorkflowStatus::Submitted)
        ->and($this->obligation->fresh()->applicability->value)->toBe('required')
        ->and($summary['counts']['exempted'])->toBe(0)
        ->and($summary['counts']['ever_submitted'])->toBe(1)
        ->and(ReviewStep::query()->where('submission_id', $submission->id)->where('status', 'pending')->count())->toBe(1);
});

it('prevents a person from deciding on a report they submitted', function (): void {
    // One person holds every permission on both sides.
    $both = $this->s->user([...ReportingRoles::OFFICER, ...ReportingRoles::APPROVER], ['A', 'HQ']);
    $submission = $this->s->writeAndSubmit($this->obligation, $both);

    expect(fn () => $this->s->decide($submission, $both, ReviewDecision::Approve))->toThrow(WorkflowViolation::class, 'self_review_forbidden')
        ->and($this->obligation->fresh()->workflow_status)->toBe(WorkflowStatus::Submitted);
});

it('lets only the receiving side review, and only with the permission', function (): void {
    $submission = $this->s->writeAndSubmit($this->obligation, $this->s->officer('A'));

    // A reviewer of an unrelated institution, and the reporting institution's own officer.
    expect(fn () => $this->s->decide($submission, $this->s->reviewer('X'), ReviewDecision::Approve))->toThrow(ReportingAccessDenied::class)
        ->and(fn () => $this->s->decide($submission, $this->s->officer('A'), ReviewDecision::Approve))->toThrow(ReportingAccessDenied::class)
        ->and(ReviewStep::query()->where('submission_id', $submission->id)->where('status', 'decided')->count())->toBe(0);
});

it('reopens an approved report only with permission and a reason, leaving the approved version as it was', function (): void {
    $submission = $this->s->writeAndSubmit($this->obligation, $this->s->officer('A'));
    $this->s->approve($submission);
    $reviews = app(ReviewReports::class);

    expect(fn () => $reviews->reopen($this->s->actor($this->s->reviewer()), $this->obligation->id, 'Wrong figures'))->toThrow(ReportingAccessDenied::class)
        ->and(fn () => $reviews->reopen($this->s->actor($this->s->approver()), $this->obligation->id, ' '))->toThrow(WorkflowViolation::class, 'reason_required');

    $reviews->reopen($this->s->actor($this->s->approver()), $this->obligation->id, 'The annex was for the wrong month.');
    $obligation = $this->obligation->fresh();

    expect($obligation->workflow_status)->toBe(WorkflowStatus::Returned)
        ->and($obligation->current_version)->toBe(2)
        ->and($submission->fresh()->status)->toBe('approved')
        ->and($obligation->first_submission_id)->toBe($submission->id);
});

it('keeps the submission when generating the Word file fails, and generates it later', function (): void {
    // The renderer is broken (a missing library, a full disk).
    app()->bind(DocumentRenderer::class, fn () => new class implements DocumentRenderer
    {
        public function renderWord(ReportDocument $document, string $targetPath): void
        {
            throw new RuntimeException('Renderer unavailable');
        }
    });

    $submission = $this->s->writeAndSubmit($this->obligation, $this->s->officer('A'));
    $artifact = Artifact::query()->where('owner_id', $submission->id)->where('format', 'docx')->firstOrFail();

    expect(Submission::query()->whereKey($submission->id)->exists())->toBeTrue()
        ->and($this->obligation->fresh()->workflow_status)->toBe(WorkflowStatus::Submitted)
        ->and($this->obligation->fresh()->first_submitted_at)->not->toBeNull()
        ->and($artifact->status)->toBe('failed')
        ->and($artifact->error)->toContain('Renderer unavailable');

    // Repaired; the scheduled task picks the failed file up again.
    app()->forgetInstance(DocumentRenderer::class);
    app()->bind(DocumentRenderer::class, fn ($app) => new PhpWordDocumentRenderer($app->make(HtmlToWord::class), 'Nyala'));
    ReportingScenario::clock('2026-11-10 09:20');
    $this->artisan('institutional-reporting:run', ['task' => 'artifacts'])->assertSuccessful();

    expect($artifact->fresh()->status)->toBe('completed')
        ->and(Storage::disk('local')->exists($artifact->fresh()->path))->toBeTrue();
});

it('blocks a late first submission when the schedule says so, until an extension is granted', function (): void {
    $s = $this->s;
    Cycle::query()->whereKey($this->cycle->id)->update(['late_policy' => 'block']);
    $officer = $s->officer('A');
    $s->write($this->obligation, $officer);

    ReportingScenario::clock('2026-11-16 09:00');
    expect(fn () => $s->submit($this->obligation, $officer))->toThrow(WorkflowViolation::class, 'late_blocked');
});
