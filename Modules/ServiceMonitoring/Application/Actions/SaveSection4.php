<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Actions;

use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\ServiceMonitoring\Application\Services\ServiceMonitoringAudit;
use Modules\ServiceMonitoring\Application\Services\SubmissionGuard;
use Modules\ServiceMonitoring\Domain\Forms\FormDefinition;
use Modules\ServiceMonitoring\Domain\Models\ServiceTotals;
use Modules\ServiceMonitoring\Domain\Models\StandardPerformance;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/**
 * Saves the institution's service totals (shared by Sections III and IV) and
 * the Section IV counts. Partial input is kept (autosave); the sum and
 * below-standard rules are enforced before submission (SubmissionValidator).
 */
final readonly class SaveSection4
{
    public function __construct(private SubmissionGuard $guard, private ServiceMonitoringAudit $audit) {}

    /**
     * @param  array{main_total: ?int, sub_total: ?int}  $totals
     * @param  array<string, mixed>  $performance
     */
    public function execute(Submission $submission, array $totals, array $performance, User $actor): void
    {
        $this->guard->assertWritable($submission);

        DB::transaction(function () use ($submission, $totals, $performance, $actor): void {
            ServiceTotals::query()->updateOrCreate(['submission_id' => $submission->id], [
                'main_total' => $totals['main_total'] ?? null,
                'sub_total' => $totals['sub_total'] ?? null,
            ]);
            if ($performance !== []) {
                StandardPerformance::query()->updateOrCreate(['submission_id' => $submission->id],
                    array_intersect_key($performance, array_flip([...FormDefinition::SECTION_4, 'below_reasons', 'actions_taken'])));
            }
            $this->audit->record(AuditEventType::ServiceMonitoringSectionSaved, $actor, $submission, $submission->organization_id, [
                'section' => 4, 'totals' => $totals, 'counts' => array_intersect_key($performance, array_flip(FormDefinition::SECTION_4)),
            ]);
        });
    }
}
