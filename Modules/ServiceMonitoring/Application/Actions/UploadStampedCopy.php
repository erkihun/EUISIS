<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Modules\ServiceMonitoring\Application\Services\AttachmentStore;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Application\Services\SubmissionGuard;
use Modules\ServiceMonitoring\Domain\Models\MonitoringAttachment;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/** Uploads the stamped copy of a report (D11): optional unless the setting requires it. */
final readonly class UploadStampedCopy
{
    public function __construct(private SubmissionGuard $guard, private AttachmentStore $store, private ServiceMonitoringAudit $audit) {}

    public function execute(Submission $submission, UploadedFile $file, User $actor): MonitoringAttachment
    {
        $this->guard->assertWritable($submission);
        $attachment = $this->store->store($file, MonitoringAttachment::TYPE_SUBMISSION, $submission->id, MonitoringAttachment::KIND_STAMPED_COPY, $actor);
        $this->audit->record(AuditEventType::ServiceMonitoringStampedCopyUploaded, $actor, $attachment, $submission->organization_id,
            ['submission_id' => $submission->id, 'mime' => $attachment->mime, 'size' => $attachment->size, 'sha256' => $attachment->sha256]);

        return $attachment;
    }
}
