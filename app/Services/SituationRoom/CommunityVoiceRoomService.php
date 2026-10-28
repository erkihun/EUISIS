<?php

declare(strict_types=1);

namespace App\Services\SituationRoom;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Assembles the Situation Room sections and caches each one briefly.
 *
 * A cache key is built from everything that decides what a user may see —
 * the scope signature (published version, institution, authorized
 * organizations, visible target types, own-employee exclusion), the filters
 * and the section — so two leaders with different reach never share a value.
 * Every payload says when it was computed (generated_at).
 */
final class CommunityVoiceRoomService
{
    public const SECTIONS = ['headline', 'comparison', 'breakdown', 'supporting'];

    public function __construct(
        private readonly CommunityVoiceQuery $voice,
        private readonly AttentionCenter $attention,
        private readonly ManagementSummary $summary,
        private readonly SupportingIndicators $supporting,
        private readonly EmployeeTargetedQuery $employees,
    ) {}

    /** KPIs and the trend: what loads first. */
    public function headline(SituationRoomScope $scope, SituationRoomFilters $filters): array
    {
        return $this->remember($scope, $filters, 'headline', SituationRoomMetrics::CACHE_SECONDS, fn (): array => [
            'kpis' => $this->voice->kpis($scope, $filters),
            'trend' => $this->voice->trend($scope, $filters),
        ]);
    }

    /** Institution comparison, attention items and the plain-language summary. */
    public function comparison(SituationRoomScope $scope, SituationRoomFilters $filters): array
    {
        return $this->remember($scope, $filters, 'comparison', SituationRoomMetrics::CACHE_SECONDS, function () use ($scope, $filters): array {
            $kpis = $this->headline($scope, $filters)['kpis'];
            $institutions = $this->voice->byInstitution($scope, $filters);

            return [
                'institutions' => $institutions,
                'attention' => $this->attention->items($scope, $filters, $kpis),
                'summary' => $this->summary->sentences($kpis, $institutions),
            ];
        });
    }

    /** Services, relevant work units, workflow state, target split and ratings. */
    public function breakdown(SituationRoomScope $scope, SituationRoomFilters $filters): array
    {
        return $this->remember($scope, $filters, 'breakdown', SituationRoomMetrics::CACHE_SECONDS, fn (): array => [
            'services' => $this->voice->byService($scope, $filters),
            'units' => $this->voice->byUnit($scope, $filters),
            'resolution' => $this->voice->resolution($scope, $filters),
            'targets' => $this->voice->byTarget($scope, $filters),
            'ratings' => $this->voice->ratings($scope, $filters),
        ]);
    }

    /** Workforce, EPMS, daily work, field work and cafeteria cards, plus configuration gaps. */
    public function supporting(SituationRoomScope $scope, SituationRoomFilters $filters): array
    {
        // The cards depend on the viewer's module permissions, so those join the key.
        $grants = implode(',', array_map(
            fn (array $permissions): string => implode('+', array_map(fn (string $p): string => $scope->user->can($p) ? '1' : '0', $permissions)),
            SupportingIndicators::CARDS,
        ));

        return $this->remember($scope, $filters, 'supporting:'.$grants, SituationRoomMetrics::CACHE_SECONDS, fn (): array => [
            'cards' => $this->supporting->cards($scope),
            'configuration' => $this->attention->configurationGaps($scope),
        ]);
    }

    /**
     * The Wall: aggregates only — no names of people, no narrative, no
     * identity, no attachments.
     */
    public function wall(SituationRoomScope $scope, SituationRoomFilters $filters): array
    {
        return $this->remember($scope, $filters, 'wall', SituationRoomMetrics::WALL_CACHE_SECONDS, function () use ($scope, $filters): array {

            return [
                'kpis' => $this->voice->kpis($scope, $filters),
                'trend' => $this->voice->trend($scope, $filters),
                'institutions' => $this->voice->byInstitution($scope, $filters),
                'resolution' => $this->voice->resolution($scope, $filters)['statuses'],
            ];
        });
    }

    /** Employee-targeted counts, one page. */
    public function employeeSummary(SituationRoomScope $scope, SituationRoomFilters $filters, int $page, string $sort): array
    {
        return $this->remember($scope, $filters, "employees:{$page}:{$sort}", SituationRoomMetrics::CACHE_SECONDS, fn (): array => $this->employees->summary($scope, $filters, $page, $sort));
    }

    /** Drop this user's cached sections for these filters (the Refresh button). */
    public function forget(SituationRoomScope $scope, SituationRoomFilters $filters): void
    {
        foreach (['headline', 'comparison', 'breakdown', 'wall'] as $section) {
            Cache::forget($this->key($scope, $filters, $section));
        }
        // Supporting and employee pages carry extra key parts; they expire on their own within two minutes.
    }

    public function key(SituationRoomScope $scope, SituationRoomFilters $filters, string $section): string
    {
        return 'situation-room:'.SituationRoomMetrics::VERSION.':'.sha1($scope->signature().'|'.json_encode($filters->toArray(), JSON_THROW_ON_ERROR).'|'.$section);
    }

    /** @param  Closure(): array<string, mixed>  $compute */
    private function remember(SituationRoomScope $scope, SituationRoomFilters $filters, string $section, int $seconds, Closure $compute): array
    {
        return Cache::remember($this->key($scope, $filters, $section), $seconds, fn (): array => $compute() + ['generated_at' => now()->toIso8601String()]);
    }
}
