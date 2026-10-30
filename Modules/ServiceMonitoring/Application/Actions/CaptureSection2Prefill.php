<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Modules\ServiceMonitoring\Application\Services\PrefillService;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Application\Services\SubmissionGuard;
use Modules\ServiceMonitoring\Domain\Models\PrefillValue;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/** Re-reads the Section II system figures (D18), for example after the driver list changed. */
final readonly class CaptureSection2Prefill
{
    public function __construct(private SubmissionGuard $guard, private PrefillService $prefill, private ServiceMonitoringAudit $audit) {}

    public function execute(Submission $submission, User $actor): void
    {
        $this->guard->assertWritable($submission);
        $this->prefill->capture($submission);
        $this->audit->record(AuditEventType::ServiceMonitoringPrefillCaptured, $actor, $submission, $submission->organization_id, [
            'values' => PrefillValue::query()->where('submission_id', $submission->id)->pluck('system_value', 'field')->all(),
        ]);
    }
}
