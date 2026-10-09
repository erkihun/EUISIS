<?php

declare(strict_types=1);

namespace App\Services\Assessment\Oversight;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\AssessmentCycle;
use App\Models\AssessmentResultBand;
use App\Models\AssessmentResultBandPolicy;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Result bands (docs/assessment-oversight.md#result-bands).
 *
 * Bands live in versioned policies. A draft is editable; activation
 * validates it and freezes it. A cycle pins one policy, so a later policy
 * never reclassifies an old cycle. No range is hard-coded here.
 */
class AssessmentResultDistributionService
{
    public function __construct(private readonly AssessmentCoverageService $coverage, private readonly WriteAuditLogAction $audit) {}

    /**
     * Problems that block activation. Overlaps, gaps (when full coverage is
     * required), inverted ranges and out-of-range limits are reported, never
     * silently resolved.
     *
     * @return array<int, string>
     */
    public function validate(AssessmentResultBandPolicy $policy): array
    {
        $bands = $policy->bands()->get();
        $problems = [];
        if ($bands->isEmpty()) {
            return ['A policy needs at least one band.'];
        }
        if (bccomp((string) $policy->range_min, (string) $policy->range_max, 4) >= 0) {
            $problems[] = 'The policy range minimum must be below its maximum.';
        }
        foreach ($bands as $band) {
            $cmp = bccomp((string) $band->min_score, (string) $band->max_score, 4);
            if ($cmp > 0 || ($cmp === 0 && ! ($band->min_inclusive && $band->max_inclusive))) {
                $problems[] = "Band {$band->code}: the minimum must not exceed the maximum.";
            }
            if (bccomp((string) $band->min_score, (string) $policy->range_min, 4) < 0 || bccomp((string) $band->max_score, (string) $policy->range_max, 4) > 0) {
                $problems[] = "Band {$band->code} lies outside the policy range {$policy->range_min}–{$policy->range_max}.";
            }
        }
        $sorted = $bands->sortBy([fn ($a, $b) => bccomp((string) $a->min_score, (string) $b->min_score, 4)])->values();
        for ($i = 1; $i < $sorted->count(); $i++) {
            $previous = $sorted[$i - 1];
            $current = $sorted[$i];
            $cmp = bccomp((string) $previous->max_score, (string) $current->min_score, 4);
            if ($cmp > 0 || ($cmp === 0 && $previous->max_inclusive && $current->min_inclusive)) {
                $problems[] = "Bands {$previous->code} and {$current->code} overlap.";
            } elseif ($policy->requires_full_coverage && ($cmp < 0 || ($cmp === 0 && ! $previous->max_inclusive && ! $current->min_inclusive))) {
                $problems[] = "There is a gap between bands {$previous->code} and {$current->code}.";
            }
        }
        if ($policy->requires_full_coverage) {
            $first = $sorted->first();
            $last = $sorted->last();
            if (bccomp((string) $first->min_score, (string) $policy->range_min, 4) !== 0 || ! $first->min_inclusive) {
                $problems[] = "The lowest band must start at {$policy->range_min} (inclusive).";
            }
            if (bccomp((string) $last->max_score, (string) $policy->range_max, 4) !== 0 || ! $last->max_inclusive) {
                $problems[] = "The highest band must end at {$policy->range_max} (inclusive).";
            }
        }

        return $problems;
    }

    /** @param array<int, array<string, mixed>> $bands */
    public function saveDraft(User $actor, AssessmentResultBandPolicy $policy, array $attributes, array $bands): AssessmentResultBandPolicy
    {
        if (! $policy->isEditable()) {
            throw ValidationException::withMessages(['policy' => 'An active or retired policy cannot change. Create a new version.']);
        }

        return DB::transaction(function () use ($actor, $policy, $attributes, $bands): AssessmentResultBandPolicy {
            $policy->fill($attributes)->save();
            $policy->bands()->delete();
            foreach (array_values($bands) as $index => $band) {
                $policy->bands()->create([...$band, 'sort_order' => $index + 1]);
            }
            $this->audit->execute(AuditEventType::AssessmentResultBandPolicyChanged, $actor, $policy, newValues: ['action' => 'draft_saved', 'bands' => count($bands)], request: request());

            return $policy->refresh();
        });
    }

    public function activate(User $actor, AssessmentResultBandPolicy $policy): void
    {
        if (! $policy->isEditable()) {
            throw ValidationException::withMessages(['policy' => 'Only a draft policy can be activated.']);
        }
        $problems = $this->validate($policy);
        if ($problems !== []) {
            throw ValidationException::withMessages(['bands' => $problems]);
        }
        DB::transaction(function () use ($actor, $policy): void {
            // Earlier versions of the same code retire; cycles that pinned them keep them.
            AssessmentResultBandPolicy::query()->where('code', $policy->code)->where('status', 'active')->update(['status' => 'retired']);
            $policy->update(['status' => 'active', 'activated_at' => now(), 'activated_by' => $actor->id]);
            $this->audit->execute(AuditEventType::AssessmentResultBandPolicyChanged, $actor, $policy, newValues: ['action' => 'activated', 'version_no' => $policy->version_no], request: request());
        });
    }

    /** A new draft version copying the bands of an existing one. */
    public function newVersion(User $actor, AssessmentResultBandPolicy $from): AssessmentResultBandPolicy
    {
        return DB::transaction(function () use ($actor, $from): AssessmentResultBandPolicy {
            $next = (int) AssessmentResultBandPolicy::query()->where('code', $from->code)->max('version_no') + 1;
            $policy = AssessmentResultBandPolicy::query()->create([
                ...$from->only(['code', 'name_en', 'name_am', 'range_min', 'range_max', 'requires_full_coverage']),
                'version_no' => $next, 'status' => 'draft', 'created_by' => $actor->id,
            ]);
            foreach ($from->bands as $band) {
                $policy->bands()->create($band->only(['code', 'label_en', 'label_am', 'min_score', 'max_score', 'min_inclusive', 'max_inclusive', 'sort_order']));
            }
            $this->audit->execute(AuditEventType::AssessmentResultBandPolicyChanged, $actor, $policy, newValues: ['action' => 'version_created', 'from' => $from->id], request: request());

            return $policy;
        });
    }

    /** Band of one score under a policy, or null (unclassifiable). */
    public function classify(AssessmentResultBandPolicy $policy, string $score): ?AssessmentResultBand
    {
        return $policy->bands->first(fn (AssessmentResultBand $band): bool => $this->inBand($band, $score));
    }

    public function inBand(AssessmentResultBand $band, string $score): bool
    {
        $low = bccomp($score, (string) $band->min_score, 4);
        $high = bccomp($score, (string) $band->max_score, 4);

        return ($low > 0 || ($low === 0 && $band->min_inclusive)) && ($high < 0 || ($high === 0 && $band->max_inclusive));
    }

    /**
     * Distribution of assessed employees over the cycle's pinned bands,
     * optionally broken down by a rows() dimension (organization_id, gender,
     * form_version_id, organization_unit_id). SQL only.
     *
     * @return array{policy: ?array<string, mixed>, bands: array<int, array<string, mixed>>, breakdown: array<string, array<string, int>>, assessed: int, classified: int, unclassified: int}
     */
    public function distribution(AssessmentCycle $cycle, OversightScope $scope, array $filters = [], ?string $breakdown = null): array
    {
        $policy = $cycle->bandPolicy()->with('bands')->first();
        $source = $this->coverage->source($cycle, $scope, [...$filters, 'outcome' => ['assessed']]);
        $assessed = (clone $source)->count();
        if ($policy === null) {
            return ['policy' => null, 'bands' => [], 'breakdown' => [], 'assessed' => $assessed, 'classified' => 0, 'unclassified' => $assessed];
        }

        $sums = [];
        $bindings = [];
        foreach ($policy->bands as $index => $band) {
            [$condition, $values] = $this->condition($band);
            $sums[] = "SUM(CASE WHEN {$condition} THEN 1 ELSE 0 END) AS b{$index}";
            $bindings = [...$bindings, ...$values];
        }
        $query = (clone $source)->selectRaw(implode(', ', $sums), $bindings);
        if ($breakdown !== null) {
            $query->addSelect("t.{$breakdown} as group_key")->groupBy("t.{$breakdown}");
        }
        $rows = $query->get();

        $totals = array_fill(0, $policy->bands->count(), 0);
        $grouped = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            foreach ($policy->bands as $index => $band) {
                $count = (int) ($row['b'.$index] ?? 0);
                $totals[$index] += $count;
                if ($breakdown !== null) {
                    $grouped[(string) ($row['group_key'] ?? '')][$band->code] = $count;
                }
            }
        }
        $classified = array_sum($totals);

        return [
            'policy' => $policy->only(['id', 'code', 'version_no', 'name_en', 'name_am', 'status']),
            'bands' => $policy->bands->values()->map(fn (AssessmentResultBand $band, int $index): array => [
                ...$band->only(['id', 'code', 'label_en', 'label_am', 'min_score', 'max_score', 'min_inclusive', 'max_inclusive']),
                'count' => $totals[$index], 'percent' => AssessmentCoverageService::percent($totals[$index], $assessed),
            ])->all(),
            'breakdown' => $grouped,
            'assessed' => $assessed,
            'classified' => $classified,
            'unclassified' => $assessed - $classified,
        ];
    }

    /** @return array{0: string, 1: array<int, string>} SQL condition on t.percentage and its bindings */
    public function condition(AssessmentResultBand $band, string $column = 't.percentage'): array
    {
        $low = $band->min_inclusive ? '>=' : '>';
        $high = $band->max_inclusive ? '<=' : '<';

        return ["{$column} {$low} ? AND {$column} {$high} ?", [(string) $band->min_score, (string) $band->max_score]];
    }

    /** @return Collection<int, AssessmentResultBandPolicy> */
    public function policies(): Collection
    {
        return AssessmentResultBandPolicy::query()->with('bands')->orderBy('code')->orderByDesc('version_no')->get();
    }
}
