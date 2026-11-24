<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\UseCases;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Reporting\Application\Data\ActorContext;
use Modules\Reporting\Application\Ports\ArtifactQueue;
use Modules\Reporting\Application\Ports\AuditRecorder;
use Modules\Reporting\Application\Ports\EventOutbox;
use Modules\Reporting\Application\Ports\WorkingCalendar;
use Modules\Reporting\Application\Support\ReportingGuard;
use Modules\Reporting\Application\Support\ReportingSettings;
use Modules\Reporting\Application\Support\StaleVersion;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Modules\Reporting\Contracts\Events\ReportSubmittedV1;
use Modules\Reporting\Domain\Enums\LatePolicy;
use Modules\Reporting\Domain\Enums\SubmissionMode;
use Modules\Reporting\Domain\Enums\WorkflowStatus;
use Modules\Reporting\Domain\Workflow\WorkflowRules;
use Modules\Reporting\Domain\Workflow\WorkflowViolation;
use Modules\Reporting\Infrastructure\Persistence\Models\Attachment;
use Modules\Reporting\Infrastructure\Persistence\Models\Cycle;
use Modules\Reporting\Infrastructure\Persistence\Models\Draft;
use Modules\Reporting\Infrastructure\Persistence\Models\MetricCapture;
use Modules\Reporting\Infrastructure\Persistence\Models\Obligation;
use Modules\Reporting\Infrastructure\Persistence\Models\ReportType;
use Modules\Reporting\Infrastructure\Persistence\Models\ReviewStep;
use Modules\Reporting\Infrastructure\Persistence\Models\Submission;
use Modules\Reporting\Infrastructure\Persistence\Models\TemplateSection;
use Modules\Reporting\Infrastructure\Persistence\Models\TemplateVersion;

/**
 * Submission: the one moment that counts for compliance.
 *
 * One transaction, with the obligation row locked, does all of this or none
 * of it: authorizes the actor, validates the content and its files, takes an
 * immutable snapshot, stamps the SERVER's time, issues the reference number,
 * opens the review steps, records the evidence and stores the integration
 * event and the Word-generation request. The generated document is produced
 * afterwards; a submission never waits for it and is never undone by it.
 *
 * Safe to repeat: a retry of a request that already succeeded returns the
 * existing submission, and two simultaneous requests cannot both create a
 * version (row lock, and a unique key on obligation + version).
 */
final readonly class SubmitReport
{
    public function __construct(
        private WorkingCalendar $calendar,
        private EventOutbox $outbox,
        private ArtifactQueue $artifacts,
        private AuditRecorder $audit,
        private ReportingSettings $settings,
    ) {}

    public function submit(ActorContext $actor, string $obligationId, int $expectedLock, ?string $lateReason = null): Submission
    {
        $this->settings->assertWritable();

        return DB::transaction(function () use ($actor, $obligationId, $expectedLock, $lateReason): Submission {
            $obligation = Obligation::query()->lockForUpdate()->findOrFail($obligationId);
            ReportingGuard::requireReportingSide($actor, $obligation, ReportingRoles::SUBMIT);

            // A retry after success: the version is already submitted.
            if ($obligation->workflow_status->awaitsDecision()) {
                $existing = Submission::query()->where('obligation_id', $obligation->id)->where('version_no', $obligation->current_version)->first();
                if ($existing !== null && (int) $existing->submitted_by === $actor->userId) {
                    return $existing;
                }
            }

            $draft = Draft::query()->where('obligation_id', $obligation->id)->where('version_no', $obligation->current_version)->lockForUpdate()->first()
                ?? throw new WorkflowViolation('nothing_to_submit');
            if ($draft->lock_version !== $expectedLock) {
                throw new StaleVersion($draft->lock_version);
            }

            $cycle = Cycle::query()->findOrFail($obligation->cycle_id);
            $type = ReportType::query()->findOrFail($obligation->report_type_id);
            $version = TemplateVersion::query()->findOrFail($obligation->template_version_id);
            $sections = TemplateSection::query()->where('template_version_id', $version->id)->orderBy('position')->get();
            $now = CarbonImmutable::now('UTC');
            $isFirst = $obligation->first_submitted_at === null;

            $late = WorkflowRules::assertSubmittable(
                $obligation->workflow_status, $obligation->applicability, $obligation->opens_at->toDateTimeImmutable(),
                $obligation->effective_due_at->toDateTimeImmutable(), $now->toDateTimeImmutable(),
                LatePolicy::from($cycle->late_policy), $isFirst, $lateReason,
            );

            $attachments = Attachment::query()->where('obligation_id', $obligation->id)->where('version_no', $obligation->current_version)->get();
            $this->validate($draft, $type, $version, $sections->all(), $attachments->all());

            $content = $draft->is_nil ? [] : ($draft->content ?? []);
            $submission = Submission::query()->create([
                'obligation_id' => $obligation->id,
                'version_no' => $obligation->current_version,
                'reference_no' => sprintf('RPT-%s-%s', $now->format('Ymd'), strtoupper(Str::random(8))),
                'mode' => $draft->mode,
                'content' => $content,
                'summary' => $draft->summary,
                'metadata' => $draft->metadata,
                'sections_snapshot' => $sections->map(fn (TemplateSection $s): array => $s->only(['key', 'position', 'title_en', 'title_am', 'is_required']))->all(),
                'is_nil' => $draft->is_nil,
                'nil_reason' => $draft->is_nil ? $draft->nil_reason : null,
                'completeness_declared' => $draft->completeness_declared,
                'is_late' => $late && $isFirst,
                'late_reason' => $late && $isFirst ? trim((string) $lateReason) ?: null : null,
                'content_hash' => hash('sha256', json_encode([$draft->mode, $content, $draft->summary, $draft->is_nil, $draft->nil_reason, $attachments->pluck('sha256')->sort()->values()->all()], JSON_UNESCAPED_UNICODE)),
                'submitted_by' => $actor->userId,
                'submitted_by_name' => $actor->name,
                'submitted_at' => $now,
                'status' => 'submitted',
            ]);

            Attachment::query()->whereKey($attachments->modelKeys())->update(['submission_id' => $submission->id]);
            // Indicator values shown while drafting are frozen with the version that used them.
            MetricCapture::query()->where('owner_type', 'obligation')->where('owner_id', $obligation->id)->where('is_frozen', false)
                ->update(['owner_type' => 'submission', 'owner_id' => $submission->id, 'is_frozen' => true]);

            $firstStep = $this->openReviewSteps($submission, $cycle, $now);

            $obligation->forceFill([
                'workflow_status' => WorkflowStatus::Submitted,
                'latest_submission_id' => $submission->id,
                'latest_submitted_at' => $now,
                // The first submission is recorded once and never touched again: returns,
                // resubmissions and review delay cannot change initial timeliness.
                'first_submission_id' => $obligation->first_submission_id ?? $submission->id,
                'first_submitted_at' => $obligation->first_submitted_at ?? $now,
                'revision_due_at' => null,
                'review_due_at' => $firstStep->due_at,
                'current_reviewer_user_id' => $firstStep->reviewer_user_id,
                'decided_at' => null,
                'lock_version' => $obligation->lock_version + 1,
            ])->save();

            $this->audit->record('submitted', $actor, 'reporting.submission', $submission->id, $obligation->organization_id, [
                'reference_no' => $submission->reference_no, 'version_no' => $submission->version_no, 'mode' => $submission->mode,
                'is_nil' => $submission->is_nil, 'is_late' => $submission->is_late, 'content_hash' => $submission->content_hash,
            ], obligationId: $obligation->id);

            $this->outbox->store(new ReportSubmittedV1(
                eventId: Str::uuid7()->toString(), occurredAt: $now->toIso8601String(), obligationId: $obligation->id,
                submissionId: $submission->id, versionNo: $submission->version_no, referenceNo: $submission->reference_no,
                organizationId: $obligation->organization_id, reportTypeId: $obligation->report_type_id, cycleId: $obligation->cycle_id,
                isFirstSubmission: $isFirst, isLate: $submission->is_late,
            ));
            $this->artifacts->request('submission', $submission->id, 'submitted');

            return $submission;
        });
    }

    /**
     * @param  list<TemplateSection>  $sections
     * @param  list<Attachment>  $attachments
     */
    private function validate(Draft $draft, ReportType $type, TemplateVersion $version, array $sections, array $attachments): void
    {
        foreach ($attachments as $attachment) {
            if (! in_array($attachment->scan_status, ['clean', 'not_scanned'], true)) {
                throw new WorkflowViolation('attachment_not_clean', ['name' => $attachment->original_name]);
            }
        }
        if (! in_array(SubmissionMode::from($draft->mode), SubmissionMode::acceptedBy($type->submission_mode), true)) {
            throw new WorkflowViolation('mode_not_accepted');
        }

        if ($draft->is_nil) {
            // A Nil Report is a submission that goes to review, not an exemption.
            if (! $type->allow_nil_report) {
                throw new WorkflowViolation('nil_not_allowed');
            }
            if (trim((string) $draft->nil_reason) === '') {
                throw new WorkflowViolation('nil_reason_required');
            }

            return;
        }

        if ($draft->mode === SubmissionMode::Structured->value) {
            foreach ($sections as $section) {
                if ($section->is_required && self::isBlank($draft->content[$section->key] ?? null)) {
                    throw new WorkflowViolation('section_required', ['section' => $section->key]);
                }
            }
        } else {
            // An uploaded Word file's narrative cannot be validated reliably: the submitter
            // declares completeness and the reviewer verifies it.
            if (array_filter($attachments, fn (Attachment $a): bool => $a->kind === 'report_document') === []) {
                throw new WorkflowViolation('report_document_required');
            }
            if (trim((string) $draft->summary) === '') {
                throw new WorkflowViolation('summary_required');
            }
            if (! $draft->completeness_declared) {
                throw new WorkflowViolation('completeness_declaration_required');
            }
        }

        $present = array_filter(array_map(fn (Attachment $a): ?string => $a->requirement_key, $attachments));
        foreach ($version->required_attachments ?? [] as $required) {
            if (! in_array($required['key'], $present, true)) {
                throw new WorkflowViolation('attachment_required', ['attachment' => $required['key']]);
            }
        }
    }

    /** Sequential steps from the cycle's pinned workflow; only the first is open. */
    private function openReviewSteps(Submission $submission, Cycle $cycle, CarbonImmutable $now): ReviewStep
    {
        $first = null;
        foreach (array_values($cycle->workflow['steps'] ?? []) as $index => $step) {
            $created = ReviewStep::query()->create([
                'submission_id' => $submission->id, 'obligation_id' => $submission->obligation_id, 'step_no' => $index + 1,
                'name_en' => $step['name_en'], 'name_am' => $step['name_am'], 'required_permission' => $step['permission'],
                'reviewer_user_id' => $cycle->reviewer_user_ids[$index] ?? null,
                'status' => $index === 0 ? 'pending' : 'waiting',
                'due_at' => $index === 0 ? $this->calendar->addWorkingDays($now->toDateTimeImmutable(), $cycle->review_sla_working_days) : null,
            ]);
            $first ??= $created;
        }

        return $first ?? throw new WorkflowViolation('template_incomplete');
    }

    private static function isBlank(?string $html): bool
    {
        return trim(html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\u{00A0}") === '';
    }
}
