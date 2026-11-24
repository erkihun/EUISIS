<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Queries;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Modules\Reporting\Application\Data\ActorContext;
use Modules\Reporting\Application\Ports\ReportingAccessResolver;
use Modules\Reporting\Application\Support\ReportingGuard;
use Modules\Reporting\Domain\Compliance\ComplianceTally;
use Modules\Reporting\Domain\Compliance\ObligationFacts;
use Modules\Reporting\Domain\Compliance\TimelinessRule;
use Modules\Reporting\Domain\Enums\Applicability;
use Modules\Reporting\Domain\Enums\WorkflowStatus;
use Modules\Reporting\Infrastructure\Persistence\Models\ApplicabilityDecision;
use Modules\Reporting\Infrastructure\Persistence\Models\Cycle;
use Modules\Reporting\Infrastructure\Persistence\Models\Extension;
use Modules\Reporting\Infrastructure\Persistence\Models\FollowUp;
use Modules\Reporting\Infrastructure\Persistence\Models\Obligation;

/**
 * The compliance register and everything counted from it.
 *
 * One query builder (`base`) carries the actor's scope and the filters; the
 * register page, the dashboard counts, the institution–period matrix, the
 * trend and the export all start from it and classify each obligation with
 * the same domain rules, so their figures reconcile by construction.
 *
 * As-of reporting: with a past `as_of`, each obligation is seen with the
 * deadline and applicability in force at that moment (from the dated
 * extension and exemption records) and only the submissions made by then.
 * The workflow step is known as of then only as "submitted or not"; the
 * finer current step is shown and labelled current. With `as_of`, the
 * timeliness and applicability filters are not applied (they would read
 * today's columns).
 */
final readonly class ComplianceQuery
{
    public function __construct(private ReportingAccessResolver $access) {}

    /**
     * @param  array<string, mixed>  $filters  cycle_id, report_type_id, organization_id, workflow, timeliness, applicability, search, due_from, due_to, as_of
     * @return Builder<Obligation>
     */
    public function base(ActorContext $actor, array $filters): Builder
    {
        $asOf = $this->asOf($filters);
        $historical = isset($filters['as_of']);
        $t = $asOf->format('Y-m-d H:i:s');

        $query = ReportingGuard::visible(Obligation::query(), $actor)
            ->when($filters['cycle_id'] ?? null, fn (Builder $q, string $id) => $q->where('cycle_id', $id))
            ->when($filters['report_type_id'] ?? null, fn (Builder $q, string $id) => $q->where('report_type_id', $id))
            ->when($filters['organization_id'] ?? null, fn (Builder $q, string $id) => $q->where('organization_id', $id))
            ->when(is_array($filters['organization_ids'] ?? null), fn (Builder $q) => $q->whereIn('organization_id', $filters['organization_ids']))
            ->when($filters['workflow'] ?? null, fn (Builder $q, string $status) => $q->where('workflow_status', $status))
            ->when($filters['due_from'] ?? null, fn (Builder $q, string $date) => $q->where('effective_due_at', '>=', self::localDay($date, false)))
            ->when($filters['due_to'] ?? null, fn (Builder $q, string $date) => $q->where('effective_due_at', '<=', self::localDay($date, true)))
            ->when($filters['search'] ?? null, function (Builder $q, string $term): void {
                $like = '%'.addcslashes(mb_strtolower(trim($term)), '%_\\').'%';
                $q->where(fn (Builder $w) => $w->whereRaw('LOWER(organization_name_en) LIKE ?', [$like])
                    ->orWhere('organization_name_am', 'like', $like)->orWhereRaw('LOWER(unit_name_en) LIKE ?', [$like])->orWhere('unit_name_am', 'like', $like)->orWhereRaw('LOWER(organization_code) LIKE ?', [$like]));
            });

        if ($historical) {
            return $query->where('created_at', '<=', $t);
        }

        $unsubmitted = fn (Builder $q) => $q->whereNull('first_submitted_at');
        $query->when($filters['applicability'] ?? null, fn (Builder $q, string $value) => $q->where('applicability', $value));

        return match ($filters['timeliness'] ?? null) {
            'on_time' => $query->where('applicability', 'required')->whereNotNull('first_submitted_at')->whereColumn('first_submitted_at', '<=', 'effective_due_at')->where('effective_due_at', '<=', $t),
            'early' => $query->where('applicability', 'required')->whereNotNull('first_submitted_at')->where('effective_due_at', '>', $t),
            'late' => $query->where('applicability', 'required')->whereNotNull('first_submitted_at')->whereColumn('first_submitted_at', '>', 'effective_due_at'),
            'overdue' => $query->where('applicability', 'required')->where($unsubmitted)->where('effective_due_at', '<=', $t),
            'not_due' => $query->where('applicability', 'required')->where($unsubmitted)->where('effective_due_at', '>', $t),
            default => $query,
        };
    }

    /**
     * One page of the register.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function register(ActorContext $actor, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $asOf = $this->asOf($filters);
        $page = $this->base($actor, $filters)->with('cycle.reportType')->orderByDesc('effective_due_at')->orderBy('organization_code')->orderBy('id')
            ->paginate(max(5, min($perPage, 100)))->withQueryString();
        $rows = $this->rows($page->getCollection(), $asOf, isset($filters['as_of']));

        return $page->setCollection(collect($rows));
    }

    /**
     * Every row of the register for an export, in the same order and scope, in bounded memory.
     *
     * @param  array<string, mixed>  $filters
     * @param  Closure(array<string, mixed>): void  $each
     */
    public function eachRow(ActorContext $actor, array $filters, Closure $each): void
    {
        $asOf = $this->asOf($filters);
        $this->base($actor, $filters)->with('cycle.reportType')->chunkById(500, function (Collection $chunk) use ($asOf, $filters, $each): void {
            foreach ($this->rows($chunk, $asOf, isset($filters['as_of'])) as $row) {
                $each($row);
            }
        });
    }

    /**
     * Counts and rates over the whole filtered set (not just one page).
     *
     * @param  array<string, mixed>  $filters
     * @return array{as_of: string, counts: array<string, int>, rates: array{submission_rate: ?float, on_time_rate: ?float, approval_rate: ?float}, follow_ups: array{open: int, overdue: int}}
     */
    public function summary(ActorContext $actor, array $filters): array
    {
        $asOf = $this->asOf($filters);
        $tally = new ComplianceTally($asOf);
        $this->base($actor, $filters)->chunkById(1000, function (Collection $chunk) use ($tally, $asOf, $filters): void {
            foreach ($this->facts($chunk, $asOf, isset($filters['as_of'])) as $facts) {
                $tally->add($facts);
            }
        });

        // Follow-up actions have their own lifecycle; they are counted beside compliance, never inside it.
        $followUps = FollowUp::query()->whereIn('obligation_id', $this->base($actor, $filters)->select('id'))->whereIn('status', ['open', 'in_progress']);

        return [
            'as_of' => CarbonImmutable::instance($asOf)->toIso8601String(),
            'counts' => $tally->counts(),
            'rates' => $tally->rates(),
            'follow_ups' => ['open' => (clone $followUps)->count(), 'overdue' => (clone $followUps)->where('due_at', '<', $asOf->format('Y-m-d H:i:s'))->count()],
        ];
    }

    /**
     * Institutions down, the report type's latest periods across.
     *
     * @param  array<string, mixed>  $filters  report_type_id is required
     * @return array{cycles: list<array<string, mixed>>, institutions: LengthAwarePaginator<int, array<string, mixed>>}
     */
    public function matrix(ActorContext $actor, array $filters, int $periods = 6, int $perPage = 25): array
    {
        $asOf = $this->asOf($filters);
        $cycles = Cycle::query()->where('report_type_id', $filters['report_type_id'] ?? '')->orderByDesc('period_end')->limit(max(1, min($periods, 12)))->get()->reverse()->values();
        $scoped = fn (): Builder => $this->base($actor, [...$filters, 'cycle_id' => null, 'timeliness' => null])->whereIn('cycle_id', $cycles->modelKeys());

        // One row per reporting subject: an institution, or a work unit inside one.
        $institutions = $scoped()->whereNull('person_user_id')->select('subject_key')
            ->selectRaw('MIN(organization_id) AS organization_id, MIN(organization_unit_id) AS organization_unit_id, MIN(organization_code) AS organization_code, MIN(organization_name_en) AS organization_name_en, MIN(organization_name_am) AS organization_name_am, MIN(unit_code) AS unit_code, MIN(unit_name_en) AS unit_name_en, MIN(unit_name_am) AS unit_name_am')
            ->groupBy('subject_key')->orderBy('organization_code')->orderBy('unit_code')->orderBy('subject_key')->paginate(max(5, min($perPage, 100)))->withQueryString();

        $cells = [];
        $obligations = $scoped()->whereNull('person_user_id')->whereIn('subject_key', $institutions->getCollection()->pluck('subject_key'))->get();
        $subjects = $obligations->pluck('subject_key', 'id');
        foreach ($this->rows($obligations, $asOf, isset($filters['as_of']), withNames: false) as $row) {
            $cells[$subjects[$row['id']]][$row['cycle_id']] = ['id' => $row['id'], 'timeliness' => $row['timeliness'], 'workflow' => $row['workflow'], 'applicability' => $row['applicability']];
        }

        return [
            'cycles' => $cycles->map(fn (Cycle $c): array => ['id' => $c->id, 'label_en' => $c->period_label_en, 'label_am' => $c->period_label_am, 'due_at' => $c->due_at->toIso8601String()])->all(),
            'institutions' => $institutions->setCollection($institutions->getCollection()->map(fn (Obligation $o): array => [
                'id' => $o->subject_key, 'code' => $o->organization_code, 'name_en' => $o->organization_name_en, 'name_am' => $o->organization_name_am,
                'unit' => $o->organization_unit_id === null ? null : ['id' => $o->organization_unit_id, 'code' => $o->unit_code, 'name_en' => $o->unit_name_en, 'name_am' => $o->unit_name_am],
                'cells' => $cells[$o->subject_key] ?? [],
            ])),
        ];
    }

    /**
     * Rates per cycle, oldest first, for the cycles already due.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function trend(ActorContext $actor, array $filters, int $cycles = 12): array
    {
        $asOf = $this->asOf($filters);
        $recent = Cycle::query()->when($filters['report_type_id'] ?? null, fn (Builder $q, string $id) => $q->where('report_type_id', $id))
            ->where('due_at', '<=', $asOf->format('Y-m-d H:i:s'))->orderByDesc('due_at')->limit(max(1, min($cycles, 24)))->get()->reverse()->values();
        if ($recent->isEmpty()) {
            return [];
        }
        /** @var array<string, ComplianceTally> $tallies */
        $tallies = [];
        $this->base($actor, [...$filters, 'cycle_id' => null, 'timeliness' => null])->whereIn('cycle_id', $recent->modelKeys())
            ->chunkById(1000, function (Collection $chunk) use (&$tallies, $asOf, $filters): void {
                foreach ($this->facts($chunk, $asOf, isset($filters['as_of'])) as $id => $facts) {
                    $cycleId = $chunk->find($id)->cycle_id;
                    ($tallies[$cycleId] ??= new ComplianceTally($asOf))->add($facts);
                }
            });

        return $recent->filter(fn (Cycle $c): bool => isset($tallies[$c->id]))->map(fn (Cycle $c): array => [
            'cycle_id' => $c->id, 'label_en' => $c->period_label_en, 'label_am' => $c->period_label_am, 'due_at' => $c->due_at->toIso8601String(),
            'due_required' => $tallies[$c->id]->counts()['due_required'], ...$tallies[$c->id]->rates(),
        ])->values()->all();
    }

    /** The start or the end of a business day (Africa/Addis_Ababa), as the UTC value the tables hold. */
    public static function localDay(string $date, bool $end): string
    {
        $day = CarbonImmutable::parse($date, 'Africa/Addis_Ababa');

        return ($end ? $day->endOfDay() : $day->startOfDay())->utc()->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $filters */
    public function asOf(array $filters): DateTimeImmutable
    {
        return isset($filters['as_of'])
            ? CarbonImmutable::parse((string) $filters['as_of'], 'Africa/Addis_Ababa')->endOfDay()->utc()->toDateTimeImmutable()
            : CarbonImmutable::now('UTC')->toDateTimeImmutable();
    }

    /**
     * The compliance facts of each obligation at the as-of time.
     *
     * @param  Collection<int, Obligation>  $obligations
     * @return array<string, ObligationFacts> keyed by obligation id
     */
    public function facts(Collection $obligations, DateTimeImmutable $asOf, bool $historical): array
    {
        if (! $historical) {
            return $obligations->mapWithKeys(fn (Obligation $o): array => [$o->id => $o->facts()])->all();
        }
        $t = $asOf->format('Y-m-d H:i:s');
        $ids = $obligations->modelKeys();
        // The deadline in force then: the one replaced by the first extension decided after as-of.
        $later = Extension::query()->whereIn('obligation_id', $ids)->where('status', 'approved')->where('decided_at', '>', $t)->orderBy('decided_at')->get()->groupBy('obligation_id');
        $decisions = ApplicabilityDecision::query()->whereIn('obligation_id', $ids)->orderBy('effective_at')->get()->groupBy('obligation_id');

        $facts = [];
        foreach ($obligations as $o) {
            $due = $later->get($o->id)?->first()?->previous_due_at ?? $o->effective_due_at;
            $applicability = $o->applicability;
            if ($decisions->has($o->id)) {
                $inForce = $decisions->get($o->id)->filter(fn (ApplicabilityDecision $d): bool => $d->effective_at <= $asOf)->last();
                $applicability = $inForce === null ? Applicability::Required : Applicability::from($inForce->applicability);
            }
            $submitted = $o->first_submitted_at !== null && $o->first_submitted_at <= $asOf;
            $facts[$o->id] = new ObligationFacts(
                applicability: $applicability,
                workflow: $submitted ? $o->workflow_status : WorkflowStatus::NotStarted,
                originalDueAt: $o->original_due_at->toDateTimeImmutable(),
                effectiveDueAt: $due->toDateTimeImmutable(),
                firstSubmittedAt: $submitted ? $o->first_submitted_at->toDateTimeImmutable() : null,
                reviewDueAt: $submitted ? $o->review_due_at?->toDateTimeImmutable() : null,
            );
        }

        return $facts;
    }

    /**
     * @param  Collection<int, Obligation>  $obligations
     * @return list<array<string, mixed>>
     */
    private function rows(Collection $obligations, DateTimeImmutable $asOf, bool $historical, bool $withNames = true): array
    {
        $facts = $this->facts($obligations, $asOf, $historical);
        $reviewers = $withNames ? $this->access->users($obligations->pluck('current_reviewer_user_id')->filter()->map(intval(...))->unique()->values()->all()) : [];

        return $obligations->map(function (Obligation $o) use ($facts, $asOf, $reviewers): array {
            $f = $facts[$o->id];

            return [
                'id' => $o->id,
                'cycle_id' => $o->cycle_id,
                'organization' => ['id' => $o->organization_id, 'code' => $o->organization_code, 'name_en' => $o->organization_name_en, 'name_am' => $o->organization_name_am],
                'unit' => $o->organization_unit_id === null ? null : ['id' => $o->organization_unit_id, 'code' => $o->unit_code, 'name_en' => $o->unit_name_en, 'name_am' => $o->unit_name_am],
                'is_personal' => $o->person_user_id !== null,
                'accountable_name' => $o->accountable_name,
                'preparer_name' => $o->preparer_name,
                'report_type' => ['name_en' => $o->cycle?->reportType?->name_en, 'name_am' => $o->cycle?->reportType?->name_am],
                'period' => ['label_en' => $o->cycle?->period_label_en, 'label_am' => $o->cycle?->period_label_am],
                'original_due_at' => $o->original_due_at->toIso8601String(),
                'effective_due_at' => CarbonImmutable::instance($f->effectiveDueAt)->toIso8601String(),
                'deadline_extended' => $f->effectiveDueAt != $f->originalDueAt,
                'first_submitted_at' => $f->firstSubmittedAt === null ? null : CarbonImmutable::instance($f->firstSubmittedAt)->toIso8601String(),
                'workflow' => $f->workflow->value,
                'applicability' => $f->applicability->value,
                'timeliness' => $f->applicability === Applicability::Required ? TimelinessRule::of($f->firstSubmittedAt, $f->effectiveDueAt, $asOf)->value : null,
                'timeliness_original' => $f->applicability === Applicability::Required ? TimelinessRule::of($f->firstSubmittedAt, $f->originalDueAt, $asOf)->value : null,
                'days_overdue' => $f->applicability === Applicability::Required ? TimelinessRule::daysOverdue($f->firstSubmittedAt, $f->effectiveDueAt, $asOf) : 0,
                'reviewer_name' => $o->current_reviewer_user_id === null ? null : ($reviewers[(int) $o->current_reviewer_user_id]->name ?? null),
                'review_due_at' => $f->reviewDueAt === null ? null : CarbonImmutable::instance($f->reviewDueAt)->toIso8601String(),
                'review_overdue' => $f->workflow->awaitsDecision() && $f->reviewDueAt !== null && $f->reviewDueAt < $asOf,
                'version' => $o->current_version,
            ];
        })->values()->all();
    }
}
