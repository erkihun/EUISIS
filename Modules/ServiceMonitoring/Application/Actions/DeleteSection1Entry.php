<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Application\Services\SubmissionGuard;
use Modules\ServiceMonitoring\Domain\Models\AccountabilityEntry;

/** Removes a Section I.1 row from an editable submission and renumbers the rest (ተ/ቁ). */
final readonly class DeleteSection1Entry
{
    public function __construct(private SubmissionGuard $guard, private ServiceMonitoringAudit $audit) {}

    public function execute(AccountabilityEntry $entry, User $actor): void
    {
        $submission = $entry->submission;
        $this->guard->assertWritable($submission);

        DB::transaction(function () use ($entry, $submission, $actor): void {
            $entry->delete();
            AccountabilityEntry::query()->where('submission_id', $submission->id)->orderBy('sequence')->get()
                ->each(function (AccountabilityEntry $row, int $index): void {
                    if ($row->sequence !== $index + 1) {
                        $row->forceFill(['sequence' => $index + 1])->save();
                    }
                });
            $this->audit->record(AuditEventType::ServiceMonitoringEntryDeleted, $actor, $submission, $submission->organization_id,
                ['section' => 1, 'entry_id' => $entry->id]);
        });
    }
}
