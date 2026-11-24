<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\UseCases;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Reporting\Application\Data\ActorContext;
use Modules\Reporting\Application\Ports\AuditRecorder;
use Modules\Reporting\Application\Ports\OrganizationDirectory;
use Modules\Reporting\Application\Ports\ReportingAccessResolver;
use Modules\Reporting\Application\Support\CyclePlanner;
use Modules\Reporting\Application\Support\ReportingGuard;
use Modules\Reporting\Application\Support\ReportingSettings;
use Modules\Reporting\Contracts\Data\ReportingRoles;
use Modules\Reporting\Domain\Calendar\PeriodCalculator;
use Modules\Reporting\Domain\Calendar\ReportingPeriod;
use Modules\Reporting\Domain\Enums\Applicability;
use Modules\Reporting\Domain\Enums\CalendarBasis;
use Modules\Reporting\Domain\Enums\Frequency;
use Modules\Reporting\Domain\Enums\WorkflowStatus;
use Modules\Reporting\Domain\Workflow\WorkflowViolation;
use Modules\Reporting\Infrastructure\Persistence\Models\Cycle;
use Modules\Reporting\Infrastructure\Persistence\Models\Obligation;
use Modules\Reporting\Infrastructure\Persistence\Models\ReportType;
use Modules\Reporting\Infrastructure\Persistence\Models\Schedule;
use Modules\Reporting\Infrastructure\Persistence\Models\ScheduleTarget;
use Modules\Reporting\Infrastructure\Persistence\Models\TemplateVersion;

/**
 * Publishing a cycle creates one expected obligation for EVERY required
 * reporting entity, whether or not anyone there ever opens the module. That
 * row is what makes a missing report visible.
 *
 * Publication is idempotent: the cycle is unique per schedule and period, the
 * obligation per cycle and subject, both by database constraint. Running it
 * twice, or two workers running it at once, adds nothing the second time; it
 * also picks up targets added to the schedule since the first run.
 */
final readonly class PublishCycles
{
    public function __construct(
        private CyclePlanner $planner,
        private PeriodCalculator $periods,
        private OrganizationDirectory $organizations,
        private ReportingAccessResolver $access,
        private AuditRecorder $audit,
        private ReportingSettings $settings,
    ) {}

    /** Publishes one period of a schedule on a person's instruction. */
    public function publishPeriod(ActorContext $actor, string $scheduleId, string $anyDateInPeriod): Cycle
    {
        ReportingGuard::require($actor, ReportingRoles::PUBLISH_SCHEDULES);
        $this->settings->assertWritable();
        $schedule = Schedule::query()->findOrFail($scheduleId);
        $period = $this->periods->containing(Frequency::from($schedule->frequency), CalendarBasis::from($schedule->calendar_basis), $anyDateInPeriod, $schedule->fiscal_year_start_month);

        return $this->publish($schedule, $period, $actor)[0];
    }

    /**
     * Scheduled work: publishes every period of every active schedule whose
     * opening moment has come and that has no cycle yet. Because it walks the
     * periods from the schedule's start (or its last cycle), a scheduler that
     * was down catches up on its next run.
     *
     * @return array{cycles: int, obligations: int}
     */
    public function publishDue(?DateTimeImmutable $now = null): array
    {
        $now = $now === null ? CarbonImmutable::now('UTC') : CarbonImmutable::instance($now)->utc();
        $stats = ['cycles' => 0, 'obligations' => 0];

        Schedule::query()->where('is_active', true)->where('frequency', '!=', Frequency::AdHoc->value)->orderBy('id')
            ->each(function (Schedule $schedule) use ($now, &$stats): void {
                $frequency = Frequency::from($schedule->frequency);
                $basis = CalendarBasis::from($schedule->calendar_basis);
                $lastEnd = Cycle::query()->where('series_key', $schedule->id)->max('period_end');
                $from = $lastEnd === null ? $schedule->starts_on->toDateString() : CarbonImmutable::parse((string) $lastEnd)->addDay()->toDateString();
                // A period may open before it ends; look far enough ahead to see it.
                $to = $now->addDays(max(0, -$schedule->opens_offset_days) + 1)->toDateString();
                if ($schedule->ends_on !== null) {
                    $to = min($to, $schedule->ends_on->toDateString());
                }

                foreach ($this->periods->endingBetween($frequency, $basis, $from, $to, $schedule->fiscal_year_start_month) as $period) {
                    if ($this->planner->moments($this->rules($schedule), $period)['opens_at'] > $now) {
                        break;
                    }
                    $stats['cycles']++;
                    $stats['obligations'] += $this->publish($schedule, $period, null)[1];
                }
            });

        return $stats;
    }

    /**
     * A special (one-off) report: the period and deadline are given, nothing recurs.
     *
     * @param  array{report_type_id: string, period_label_en: string, period_label_am: string, period_start: string, period_end: string, opens_at: string, due_at: string, receiving_organization_id?: ?string, review_sla_working_days?: int, late_policy?: string}  $data  moments in UTC
     * @param  array<string, array<string, mixed>>  $targets  from ManageSchedules::resolveTargets()
     */
    public function publishAdHoc(ActorContext $actor, array $data, array $targets): Cycle
    {
        ReportingGuard::require($actor, ReportingRoles::PUBLISH_SCHEDULES);
        $this->settings->assertWritable();
        $type = ReportType::query()->findOrFail($data['report_type_id']);
        $version = $this->publishedVersion($type->id);
        $receiving = $data['receiving_organization_id'] ?? $type->receiving_organization_id;

        return DB::transaction(function () use ($actor, $data, $targets, $type, $version, $receiving): Cycle {
            $cycle = Cycle::query()->create([
                ...$this->cycleCommon($type, $version, $receiving),
                'schedule_id' => null, 'series_key' => 'adhoc:'.Str::uuid()->toString(), 'period_key' => 'special',
                'period_label_en' => $data['period_label_en'], 'period_label_am' => $data['period_label_am'],
                'period_start' => $data['period_start'], 'period_end' => $data['period_end'],
                'opens_at' => $data['opens_at'], 'due_at' => $data['due_at'],
                'review_sla_working_days' => $data['review_sla_working_days'] ?? 5, 'late_policy' => $data['late_policy'] ?? 'accept',
                'published_by' => $actor->userId,
            ]);
            $created = $this->createObligations($cycle, array_values($targets));
            $this->audit->record('cycle_published', $actor, 'reporting.cycle', $cycle->id, null, ['special' => true, 'obligations' => $created]);

            return $cycle;
        });
    }

    /** @return array{0: Cycle, 1: int} the cycle and how many obligations this call created */
    private function publish(Schedule $schedule, ReportingPeriod $period, ?ActorContext $actor): array
    {
        $type = ReportType::query()->findOrFail($schedule->report_type_id);
        $version = $this->publishedVersion($type->id);
        $moments = $this->planner->moments($this->rules($schedule), $period);
        $receiving = $schedule->receiving_organization_id ?? $type->receiving_organization_id;

        return DB::transaction(function () use ($schedule, $period, $actor, $type, $version, $moments, $receiving): array {
            $cycle = Cycle::query()->where('series_key', $schedule->id)->where('period_key', $period->key)->first();
            $isNew = $cycle === null;
            if ($isNew) {
                try {
                    // A savepoint, so a lost race leaves the outer transaction usable.
                    $cycle = DB::transaction(fn (): Cycle => Cycle::query()->create([
                        ...$this->cycleCommon($type, $version, $receiving),
                        'schedule_id' => $schedule->id, 'series_key' => $schedule->id, 'period_key' => $period->key,
                        'period_label_en' => $period->labelEn, 'period_label_am' => $period->labelAm,
                        'period_start' => $period->startDate, 'period_end' => $period->endDate,
                        'opens_at' => $moments['opens_at'], 'due_at' => $moments['due_at'],
                        'timezone' => $schedule->timezone, 'calendar_basis' => $schedule->calendar_basis,
                        'review_sla_working_days' => $schedule->review_sla_working_days, 'revision_days' => $schedule->revision_days,
                        'late_policy' => $schedule->late_policy, 'reviewer_user_ids' => $schedule->reviewer_user_ids ?? [],
                        'reminder_rules' => $schedule->reminder_rules, 'published_by' => $actor?->userId,
                    ]));
                } catch (UniqueConstraintViolationException) {
                    $cycle = Cycle::query()->where('series_key', $schedule->id)->where('period_key', $period->key)->firstOrFail();
                    $isNew = false;
                }
            }

            $targets = ScheduleTarget::query()->where('schedule_id', $schedule->id)->orderBy('id')->get()
                ->map(fn (ScheduleTarget $t): array => $t->only(['organization_id', 'organization_unit_id', 'person_user_id', 'preparer_user_id', 'accountable_user_id']))->all();
            $created = $this->createObligations($cycle, $targets);
            if ($isNew || $created > 0) {
                $this->audit->record('cycle_published', $actor, 'reporting.cycle', $cycle->id, null, ['period' => $period->key, 'obligations' => $created, 'new_cycle' => $isNew]);
            }

            return [$cycle, $created];
        });
    }

    /**
     * Inserts the obligations that do not exist yet; the unique key
     * (cycle, subject) makes a repeat a no-op.
     *
     * @param  list<array<string, mixed>>  $targets
     */
    private function createObligations(Cycle $cycle, array $targets): int
    {
        $created = 0;
        $now = CarbonImmutable::now('UTC');
        foreach (array_chunk($targets, 200) as $chunk) {
            $organizations = $this->organizations->findMany(array_values(array_unique(array_column($chunk, 'organization_id'))));
            $users = $this->access->users(array_values(array_filter(array_merge(array_column($chunk, 'preparer_user_id'), array_column($chunk, 'accountable_user_id'), array_column($chunk, 'person_user_id')))));
            $rows = [];
            $units = [];
            foreach ($chunk as $target) {
                $organization = $organizations[$target['organization_id']] ?? null;
                if ($organization === null) {
                    continue;
                }
                $unit = null;
                if (($target['organization_unit_id'] ?? null) !== null) {
                    $units[$organization->id] ??= $this->organizations->units($organization->id);
                    $unit = $units[$organization->id][$target['organization_unit_id']] ?? null;
                    // A unit that has been closed owes no new report.
                    if ($unit === null) {
                        continue;
                    }
                }
                $person = $target['person_user_id'] ?? null;
                $preparer = $target['preparer_user_id'] ?? $person;
                $accountable = $target['accountable_user_id'] ?? null;
                $rows[] = [
                    'id' => Str::uuid7()->toString(),
                    'cycle_id' => $cycle->id, 'report_type_id' => $cycle->report_type_id, 'template_version_id' => $cycle->template_version_id,
                    'subject_key' => $unit !== null ? "unit:{$unit->id}" : ($person === null ? "org:{$organization->id}" : "user:{$person}"),
                    'organization_id' => $organization->id, 'person_user_id' => $person,
                    'organization_unit_id' => $unit?->id, 'unit_code' => $unit?->code, 'unit_name_en' => $unit?->nameEn, 'unit_name_am' => $unit?->nameAm,
                    'organization_code' => $organization->code, 'organization_name_en' => $organization->nameEn, 'organization_name_am' => $organization->nameAm,
                    'hierarchy_snapshot' => json_encode($this->organizations->hierarchySnapshot($organization->id), JSON_UNESCAPED_UNICODE),
                    'preparer_user_id' => $preparer, 'preparer_name' => $preparer === null ? null : ($users[(int) $preparer]->name ?? null),
                    'accountable_user_id' => $accountable, 'accountable_name' => $accountable === null ? null : ($users[(int) $accountable]->name ?? null),
                    'receiving_organization_id' => $cycle->receiving_organization_id,
                    'opens_at' => $cycle->opens_at, 'original_due_at' => $cycle->due_at, 'effective_due_at' => $cycle->due_at,
                    'applicability' => Applicability::Required->value, 'workflow_status' => WorkflowStatus::NotStarted->value,
                    'current_version' => 1, 'lock_version' => 0, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
            $created += Obligation::query()->insertOrIgnore($rows);
        }

        return $created;
    }

    private function publishedVersion(string $typeId): TemplateVersion
    {
        return TemplateVersion::query()->where('report_type_id', $typeId)->where('status', 'published')->orderByDesc('version')->first()
            ?? throw new WorkflowViolation('no_published_template');
    }

    /** @return array<string, mixed> */
    private function cycleCommon(ReportType $type, TemplateVersion $version, ?string $receivingId): array
    {
        $receiving = $receivingId === null ? null : $this->organizations->find($receivingId);

        return [
            'report_type_id' => $type->id, 'template_version_id' => $version->id, 'workflow' => $version->workflow,
            'receiving_organization_id' => $receiving?->id, 'receiving_name_en' => $receiving?->nameEn, 'receiving_name_am' => $receiving?->nameAm,
            'hierarchy_version_ref' => $receiving === null ? null : $this->organizations->hierarchySnapshot($receiving->id)['version'],
            'status' => 'published', 'published_at' => CarbonImmutable::now('UTC'),
        ];
    }

    /** @return array{due_offset_days: int, opens_offset_days: int, due_time: string, timezone: string, holiday_adjustment: string} */
    private function rules(Schedule $schedule): array
    {
        return $schedule->only(['due_offset_days', 'opens_offset_days', 'due_time', 'timezone', 'holiday_adjustment']);
    }
}
