<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Application\Services\SubmissionGuard;
use Modules\ServiceMonitoring\Domain\Models\DigitalService;

/** Removes a Section III row; removing a main service removes its sub services with it. */
final readonly class DeleteSection3Entry
{
    public function __construct(private SubmissionGuard $guard, private ServiceMonitoringAudit $audit) {}

    public function execute(DigitalService $entry, User $actor): void
    {
        $submission = $entry->submission;
        $this->guard->assertWritable($submission);

        DB::transaction(function () use ($entry, $submission, $actor): void {
            DigitalService::query()->where('parent_entry_id', $entry->id)->delete();
            $entry->delete();
            $this->audit->record(AuditEventType::ServiceMonitoringEntryDeleted, $actor, $submission, $submission->organization_id, ['section' => 3, 'entry_id' => $entry->id]);
        });
    }
}
