<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\UseCases;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Reporting\Application\Data\ActorContext;
use Modules\Reporting\Application\Ports\ArtifactQueue;
use Modules\Reporting\Application\Ports\AuditRecorder;
use Modules\Reporting\Application\Ports\OrganizationDirectory;
use Modules\Reporting\Application\Ports\RichTextCleaner;
use Modules\Reporting\Application\Support\MetricSources;
use Modules\Reporting\Application\Support\ReportingAccessDenied;
use Modules\Reporting\Application\Support\ReportingSettings;
use Modules\Reporting\Application\Support\StaleVersion;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Modules\Reporting\Domain\Compliance\TimelinessRule;
use Modules\Reporting\Domain\Enums\Applicability;
use Modules\Reporting\Domain\Enums\Timeliness;
use Modules\Reporting\Domain\Enums\WorkflowStatus;
use Modules\Reporting\Domain\Workflow\WorkflowViolation;
use Modules\Reporting\Infrastructure\Persistence\Models\Consolidation;
use Modules\Reporting\Infrastructure\Persistence\Models\ConsolidationSource;
use Modules\Reporting\Infrastructure\Persistence\Models\Cycle;
use Modules\Reporting\Infrastructure\Persistence\Models\MetricCapture;
use Modules\Reporting\Infrastructure\Persistence\Models\Obligation;
use Modules\Reporting\Infrastructure\Persistence\Models\Submission;

/**
 * A parent-level report compiled from the APPROVED reports of the
 * institutions under one parent, for one cycle.
 *
 * - Every institution that owed a report and has no approved one is listed by
 *   name with its status: nobody is silently left out.
 * - Each institution enters once (unique key), with the version it was
 *   approved at; the source ids and versions are recorded.
 * - Indicators are counts of the obligations themselves, each counted once.
 *   Optional figures from other modules are captured ONCE for the parent's
 *   whole subtree through that module's public contract (never summed from the
 *   institutions' own figures, which would double count a parent and its
 *   children) and stored with source, definition, scope, period and time.
 */
final readonly class ConsolidateReports
{
    public function __construct(
        private OrganizationDirectory $organizations,
        private RichTextCleaner $richText,
        private MetricSources $metrics,
        private ArtifactQueue $artifacts,
        private AuditRecorder $audit,
        private ReportingSettings $settings,
    ) {}

    public function start(ActorContext $actor, string $cycleId, string $parentOrganizationId, string $title): Consolidation
    {
        $this->authorize($actor, $parentOrganizationId);
        $this->settings->assertWritable();
        Cycle::query()->findOrFail($cycleId);
        $parent = $this->organizations->find($parentOrganizationId) ?? throw new WorkflowViolation('unknown_organization');

        return Consolidation::query()->create([
            'cycle_id' => $cycleId, 'parent_organization_id' => $parent->id, 'parent_name_en' => $parent->nameEn, 'parent_name_am' => $parent->nameAm,
            'title' => Str::limit(trim(strip_tags($title)), 250, ''), 'status' => 'draft',
            'created_by' => $actor->userId, 'created_by_name' => $actor->name,
        ]);
    }

    /** Saves the editor's own executive summary (optimistically locked like a draft). */
    public function saveSummary(ActorContext $actor, string $consolidationId, string $title, ?string $summaryHtml, int $expectedLock): Consolidation
    {
        $this->settings->assertWritable();

        return DB::transaction(function () use ($actor, $consolidationId, $title, $summaryHtml, $expectedLock): Consolidation {
            $consolidation = Consolidation::query()->lockForUpdate()->findOrFail($consolidationId);
            $this->authorize($actor, $consolidation->parent_organization_id);
            if ($consolidation->lock_version !== $expectedLock) {
                throw new StaleVersion($consolidation->lock_version);
            }
            $consolidation->forceFill([
                'title' => Str::limit(trim(strip_tags($title)), 250, ''), 'executive_summary' => $this->richText->clean($summaryHtml),
                'lock_version' => $consolidation->lock_version + 1,
            ])->save();

            return $consolidation;
        });
    }

    /** Collects the sources as they stand now and requests the Word file. May be repeated; each run replaces the sources. */
    public function compile(ActorContext $actor, string $consolidationId): Consolidation
    {
        $this->settings->assertWritable();

        return DB::transaction(function () use ($actor, $consolidationId): Consolidation {
            $consolidation = Consolidation::query()->lockForUpdate()->findOrFail($consolidationId);
            $this->authorize($actor, $consolidation->parent_organization_id);
            $cycle = Cycle::query()->findOrFail($consolidation->cycle_id);
            $now = CarbonImmutable::now('UTC');

            // The institutions under the parent. The parent's own report, if it owes one in this cycle, is not a source of its own consolidation.
            $subtree = array_values(array_diff($this->organizations->subtreeIds($consolidation->parent_organization_id), [$consolidation->parent_organization_id]));
            $counts = ['expected' => 0, 'included' => 0, 'missing' => 0, 'exempted_or_cancelled' => 0, 'nil_reports' => 0, 'submitted_on_time' => 0, 'submitted_late' => 0];
            $missing = [];
            ConsolidationSource::query()->where('consolidation_id', $consolidation->id)->delete();

            $include = function (Obligation $obligation) use ($consolidation, $now, &$counts, &$missing): void {
                $counts['expected']++;
                $row = ['organization_id' => $obligation->organization_id, 'code' => $obligation->unit_code ?? $obligation->organization_code, 'name_en' => $obligation->subjectName('en'), 'name_am' => $obligation->subjectName('am')];
                if ($obligation->applicability !== Applicability::Required) {
                    $counts['exempted_or_cancelled']++;
                    $missing[] = [...$row, 'status' => $obligation->applicability->value];

                    return;
                }
                $timeliness = TimelinessRule::of($obligation->first_submitted_at?->toDateTimeImmutable(), $obligation->effective_due_at->toDateTimeImmutable(), $now->toDateTimeImmutable());
                $counts['submitted_on_time'] += (int) in_array($timeliness, [Timeliness::OnTime, Timeliness::Early], true);
                $counts['submitted_late'] += (int) ($timeliness === Timeliness::Late);

                $submission = $obligation->workflow_status === WorkflowStatus::Approved ? Submission::query()->find($obligation->latest_submission_id) : null;
                if ($submission === null) {
                    $counts['missing']++;
                    $missing[] = [...$row, 'status' => $obligation->first_submitted_at === null ? 'not_submitted' : $obligation->workflow_status->value];

                    return;
                }
                $counts['included']++;
                $counts['nil_reports'] += (int) $submission->is_nil;
                ConsolidationSource::query()->create([
                    'consolidation_id' => $consolidation->id, 'obligation_id' => $obligation->id, 'submission_id' => $submission->id,
                    'version_no' => $submission->version_no, 'reference_no' => $submission->reference_no, 'organization_id' => $obligation->organization_id,
                ]);
            };

            foreach (array_chunk($subtree, 500) as $ids) {
                Obligation::query()->where('cycle_id', $cycle->id)->whereNull('person_user_id')->whereIn('organization_id', $ids)->whereNull('organization_unit_id')->orderBy('organization_code')->each($include);
            }
            // The parent's own work units report inside the institution: their reports are sources of its consolidation.
            Obligation::query()->where('cycle_id', $cycle->id)->whereNull('person_user_id')->where('organization_id', $consolidation->parent_organization_id)->whereNotNull('organization_unit_id')->orderBy('unit_code')->orderBy('id')->each($include);

            MetricCapture::query()->where('owner_type', 'consolidation')->where('owner_id', $consolidation->id)->delete();
            foreach ($this->metrics->capture($subtree, $cycle->period_start->toDateString(), $cycle->period_end->toDateString()) as $metric) {
                MetricCapture::query()->create([
                    ...$metric, 'owner_type' => 'consolidation', 'owner_id' => $consolidation->id,
                    'scope' => ['parent_organization_id' => $consolidation->parent_organization_id, 'organizations' => count($subtree)],
                    'period_start' => $cycle->period_start, 'period_end' => $cycle->period_end, 'captured_at' => $now, 'is_frozen' => true,
                ]);
            }

            $consolidation->forceFill([
                'status' => 'compiled', 'missing' => $missing, 'indicators' => $counts, 'compiled_by' => $actor->userId, 'compiled_at' => $now,
                'lock_version' => $consolidation->lock_version + 1,
            ])->save();
            $this->artifacts->request('consolidation', $consolidation->id, 'consolidated');
            $this->audit->record('consolidated', $actor, 'reporting.consolidation', $consolidation->id, $consolidation->parent_organization_id, $counts);

            return $consolidation;
        });
    }

    public function authorize(ActorContext $actor, string $parentOrganizationId): void
    {
        if (! $actor->can(ReportingRoles::CONSOLIDATE) || ! $actor->reaches($parentOrganizationId)) {
            throw new ReportingAccessDenied(ReportingRoles::CONSOLIDATE);
        }
    }
}
