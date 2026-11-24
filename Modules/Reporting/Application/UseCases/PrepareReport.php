<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\UseCases;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Reporting\Application\Data\ActorContext;
use Modules\Reporting\Application\Ports\RichTextCleaner;
use Modules\Reporting\Application\Ports\UploadInspector;
use Modules\Reporting\Application\Support\ReportingGuard;
use Modules\Reporting\Application\Support\ReportingSettings;
use Modules\Reporting\Application\Support\StaleVersion;
use Modules\Reporting\Application\Support\UploadRejected;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Modules\Reporting\Domain\Enums\SubmissionMode;
use Modules\Reporting\Domain\Enums\WorkflowStatus;
use Modules\Reporting\Domain\Workflow\WorkflowRules;
use Modules\Reporting\Domain\Workflow\WorkflowViolation;
use Modules\Reporting\Infrastructure\Persistence\Models\Attachment;
use Modules\Reporting\Infrastructure\Persistence\Models\Draft;
use Modules\Reporting\Infrastructure\Persistence\Models\Obligation;
use Modules\Reporting\Infrastructure\Persistence\Models\ReportType;
use Modules\Reporting\Infrastructure\Persistence\Models\TemplateSection;

/**
 * The working draft of a report: narrative sections or an uploaded Word file,
 * and the files kept with it. Nothing here is a submission; saving a draft or
 * uploading a file never counts towards compliance.
 */
final readonly class PrepareReport
{
    public function __construct(
        private RichTextCleaner $richText,
        private UploadInspector $inspector,
        private ReportingSettings $settings,
    ) {}

    /** The draft of the obligation's current version; created empty on first use. */
    public function draft(Obligation $obligation): Draft
    {
        return Draft::query()->firstOrCreate(
            ['obligation_id' => $obligation->id, 'version_no' => $obligation->current_version],
            ['mode' => $this->defaultMode($obligation), 'content' => [], 'metadata' => [], 'is_nil' => false, 'completeness_declared' => false, 'lock_version' => 0],
        );
    }

    /**
     * Saves the draft. `expectedLock` is the lock version the editor loaded:
     * if someone else has saved since, nothing is written and StaleVersion
     * tells the editor so (two people may collaborate on one institutional
     * report, but neither silently overwrites the other).
     *
     * @param  array{mode?: string, content?: array<string, ?string>, summary?: ?string, metadata?: array<string, mixed>, is_nil?: bool, nil_reason?: ?string, completeness_declared?: bool}  $input
     */
    public function save(ActorContext $actor, string $obligationId, array $input, int $expectedLock): Draft
    {
        $this->settings->assertWritable();

        return DB::transaction(function () use ($actor, $obligationId, $input, $expectedLock): Draft {
            $obligation = Obligation::query()->lockForUpdate()->findOrFail($obligationId);
            ReportingGuard::requireReportingSide($actor, $obligation, ReportingRoles::PREPARE);
            WorkflowRules::assertEditable($obligation->workflow_status, $obligation->applicability, $obligation->opens_at->toDateTimeImmutable(), CarbonImmutable::now('UTC')->toDateTimeImmutable());

            $draft = $this->draft($obligation);
            if ($draft->lock_version !== $expectedLock) {
                throw new StaleVersion($draft->lock_version);
            }

            $type = ReportType::query()->findOrFail($obligation->report_type_id);
            $mode = SubmissionMode::from($input['mode'] ?? $draft->mode);
            if (! in_array($mode, SubmissionMode::acceptedBy($type->submission_mode), true)) {
                throw new WorkflowViolation('mode_not_accepted');
            }
            if (($input['is_nil'] ?? false) && ! $type->allow_nil_report) {
                throw new WorkflowViolation('nil_not_allowed');
            }

            $content = $draft->content ?? [];
            if (isset($input['content'])) {
                // Only the sections of the pinned template version exist; anything else is dropped.
                $keys = TemplateSection::query()->where('template_version_id', $obligation->template_version_id)->pluck('key')->all();
                foreach ($keys as $key) {
                    if (array_key_exists($key, $input['content'])) {
                        $content[$key] = $this->richText->clean($input['content'][$key]);
                    }
                }
            }

            $draft->forceFill([
                'mode' => $mode->value,
                'content' => $content,
                'summary' => array_key_exists('summary', $input) ? Str::limit(trim(strip_tags((string) $input['summary'])), 5000, '') : $draft->summary,
                'metadata' => $input['metadata'] ?? $draft->metadata,
                'is_nil' => (bool) ($input['is_nil'] ?? $draft->is_nil),
                'nil_reason' => array_key_exists('nil_reason', $input) ? trim((string) $input['nil_reason']) : $draft->nil_reason,
                'completeness_declared' => (bool) ($input['completeness_declared'] ?? $draft->completeness_declared),
                'lock_version' => $draft->lock_version + 1,
                'updated_by' => $actor->userId,
            ])->save();

            if ($obligation->workflow_status === WorkflowStatus::NotStarted) {
                $obligation->forceFill(['workflow_status' => WorkflowStatus::Draft])->save();
            }

            return $draft;
        });
    }

    /**
     * Keeps an uploaded file with the current version after inspecting it.
     * The file is stored on the private disk under a generated name; the
     * original name is kept only as text.
     *
     * @param  string  $kind  report_document (the Word report itself), annex or evidence
     */
    public function attach(ActorContext $actor, string $obligationId, string $temporaryPath, string $originalName, string $kind, ?string $requirementKey = null): Attachment
    {
        $this->settings->assertWritable();
        $obligation = Obligation::query()->findOrFail($obligationId);
        ReportingGuard::requireReportingSide($actor, $obligation, ReportingRoles::PREPARE);
        WorkflowRules::assertEditable($obligation->workflow_status, $obligation->applicability, $obligation->opens_at->toDateTimeImmutable(), CarbonImmutable::now('UTC')->toDateTimeImmutable());
        if (! in_array($kind, ['report_document', 'annex', 'evidence'], true)) {
            throw new UploadRejected('type_not_allowed');
        }

        // Inspection (and the scan, when configured) happens before the file is kept anywhere durable.
        $scan = $this->inspector->inspect($temporaryPath, $originalName, $kind);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $disk = (string) config('reporting.disk');
        $path = sprintf('reporting/%s/v%d/%s.%s', $obligation->id, $obligation->current_version, Str::uuid7()->toString(), $extension);
        $stream = fopen($temporaryPath, 'rb');
        Storage::disk($disk)->put($path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        return DB::transaction(function () use ($actor, $obligation, $kind, $requirementKey, $originalName, $disk, $path, $temporaryPath, $scan): Attachment {
            $locked = Obligation::query()->lockForUpdate()->findOrFail($obligation->id);
            if ($kind === 'report_document') {
                // One Word report per version: a new upload replaces the unsubmitted one.
                Attachment::query()->where('obligation_id', $locked->id)->where('version_no', $locked->current_version)
                    ->where('kind', 'report_document')->whereNull('submission_id')->get()
                    ->each(fn (Attachment $old) => $this->discard($old));
                $this->draft($locked);
            }
            if ($locked->workflow_status === WorkflowStatus::NotStarted) {
                $locked->forceFill(['workflow_status' => WorkflowStatus::Draft])->save();
            }

            return Attachment::query()->create([
                'obligation_id' => $locked->id, 'version_no' => $locked->current_version, 'kind' => $kind, 'requirement_key' => $requirementKey,
                'original_name' => Str::limit(basename(str_replace('\\', '/', $originalName)), 250, ''),
                'disk' => $disk, 'path' => $path,
                'mime' => $kind === 'report_document' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : (string) (mime_content_type($temporaryPath) ?: 'application/octet-stream'),
                'size' => (int) filesize($temporaryPath), 'sha256' => (string) hash_file('sha256', $temporaryPath),
                'scan_status' => $scan, 'uploaded_by' => $actor->userId,
            ]);
        });
    }

    public function removeAttachment(ActorContext $actor, string $attachmentId): void
    {
        $this->settings->assertWritable();
        DB::transaction(function () use ($actor, $attachmentId): void {
            $attachment = Attachment::query()->lockForUpdate()->findOrFail($attachmentId);
            $obligation = Obligation::query()->findOrFail($attachment->obligation_id);
            ReportingGuard::requireReportingSide($actor, $obligation, ReportingRoles::PREPARE);
            if ($attachment->submission_id !== null) {
                throw new WorkflowViolation('attachment_submitted');
            }
            $this->discard($attachment);
        });
    }

    private function discard(Attachment $attachment): void
    {
        // A file carried over from an earlier, submitted version is shared with it and stays.
        $shared = Attachment::query()->where('path', $attachment->path)->where('id', '!=', $attachment->id)->exists();
        $attachment->delete();
        if (! $shared) {
            Storage::disk($attachment->disk)->delete($attachment->path);
        }
    }

    private function defaultMode(Obligation $obligation): string
    {
        $configured = (string) ReportType::query()->whereKey($obligation->report_type_id)->value('submission_mode');

        return SubmissionMode::acceptedBy($configured)[0]->value;
    }
}
