<?php

declare(strict_types=1);

namespace App\Services\SituationRoom;

use App\Models\Organization;
use App\Models\PublicFeedbackCategory;
use App\Services\ServiceFeedback\PublicFeedbackSettings;

/**
 * "Where must management attention go?" — the Attention Required list.
 *
 * Every item comes from a written rule over structured data (statuses,
 * categories, dates), never from reading complaint text. Rules that need a
 * policy value — a response deadline, an increase threshold, a repetition
 * threshold — stay silent until that value is configured
 * (PublicFeedbackSettings::situationRoomRules); nothing is called overdue or
 * a spike on an invented number. Items are "information" unless a configured
 * rule is breached, which makes them "warning"; CRITICAL/HIGH grading is a
 * NEEDS_DECISION. Wording stays neutral: a count is not a finding.
 */
final class AttentionCenter
{
    private const T = 'employee_service_feedback';

    public function __construct(
        private readonly CommunityVoiceQuery $voice,
        private readonly PublicFeedbackSettings $settings,
    ) {}

    /**
     * @param  array{current: array<string, mixed>, previous: array<string, mixed>}  $kpis
     * @return list<array{code: string, tone: string, count: int, params: array<string, mixed>, filters: array<string, string>}>
     */
    public function items(SituationRoomScope $scope, SituationRoomFilters $filters, array $kpis): array
    {
        $rules = $this->settings->situationRoomRules();
        $items = [];
        $unresolved = SituationRoomMetrics::UNRESOLVED_STATUSES;

        // Backlog, whatever the period: every complaint still being worked.
        $backlog = $this->voice->base($scope, $filters)->toBase()
            ->where(self::T.'.type', 'complaint')
            ->whereIn(self::T.'.status', $unresolved)
            ->selectRaw('COUNT(*) AS total, MIN('.self::T.'.submitted_at) AS oldest')
            ->first();
        if ((int) ($backlog->total ?? 0) > 0) {
            $items[] = $this->item('unresolved_complaints', 'info', (int) $backlog->total, ['oldest' => $backlog->oldest ? substr((string) $backlog->oldest, 0, 10) : null], ['type' => 'complaint']);
        }

        $unrouted = (int) $this->voice->base($scope, $filters)->where(self::T.'.status', 'unrouted')->count();
        if ($unrouted > 0) {
            $items[] = $this->item('unrouted', 'info', $unrouted, [], ['status' => 'unrouted']);
        }

        foreach (SituationRoomMetrics::TYPES as $type) {
            $days = $rules['sla_days_'.$type] ?? null;
            if ($days === null) {
                continue;
            }
            $overdue = (int) $this->voice->base($scope, $filters)
                ->where(self::T.'.type', $type)
                ->whereIn(self::T.'.status', $unresolved)
                ->where(self::T.'.submitted_at', '<', now()->subDays($days))
                ->count();
            if ($overdue > 0) {
                $items[] = $this->item('overdue', 'warning', $overdue, ['type' => $type, 'days' => $days], ['type' => $type]);
            }
        }

        $threshold = $rules['spike_threshold_percent'] ?? null;
        if ($threshold !== null) {
            $minimum = $rules['spike_minimum_count'] ?? 1;
            foreach (SituationRoomMetrics::TYPES as $type) {
                $spike = $this->spike((int) ($kpis['current'][$type] ?? 0), (int) ($kpis['previous'][$type] ?? 0), $threshold, $minimum);
                if ($spike !== null) {
                    $items[] = $this->item('increase', 'warning', (int) $kpis['current'][$type], ['type' => $type, 'previous' => (int) $kpis['previous'][$type], 'percent' => $spike], ['type' => $type]);
                }
            }
            foreach ($this->categoryIncreases($scope, $filters, $threshold, $minimum) as $increase) {
                $items[] = $increase;
            }
        }

        $repeat = $rules['repeat_threshold_count'] ?? null;
        if ($repeat !== null) {
            foreach ($this->repeated($scope, $filters, $repeat) as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Institutions in view that cannot fully take part yet: no active feedback QR, no public phone.
     *
     * @return array{without_qr: int, without_public_phone: int}
     */
    public function configurationGaps(SituationRoomScope $scope): array
    {
        $organizations = Organization::query()->where('status', 'active');
        $scope->apply($organizations, 'organizations.id');

        return [
            'without_qr' => (clone $organizations)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('organization_feedback_qr_codes as q')
                ->whereColumn('q.organization_id', 'organizations.id')->where('q.status', 'active'))->count(),
            'without_public_phone' => (clone $organizations)->whereNull('public_contact_phone')->count(),
        ];
    }

    /** Percentage increase when it meets the configured rule; null when it does not or cannot be computed. */
    private function spike(int $current, int $previous, int $threshold, int $minimum): ?int
    {
        // No baseline, no percentage: a rise from zero is not reported as an infinite increase.
        if ($previous === 0 || $current - $previous < $minimum) {
            return null;
        }
        $percent = (int) round((($current - $previous) / $previous) * 100);

        return $percent >= $threshold ? $percent : null;
    }

    /** @return list<array<string, mixed>> */
    private function categoryIncreases(SituationRoomScope $scope, SituationRoomFilters $filters, int $threshold, int $minimum): array
    {
        $count = fn ($from, $to) => $this->voice->base($scope, $filters, $from, $to)->toBase()
            ->whereNotNull(self::T.'.category_id')
            ->selectRaw(self::T.'.category_id AS category_id, COUNT(*) AS total')
            ->groupBy(self::T.'.category_id')
            ->pluck('total', 'category_id');

        $current = $count($filters->start, $filters->end);
        $previous = $count($filters->previousStart, $filters->previousEnd);

        $found = [];
        foreach ($current as $categoryId => $total) {
            $percent = $this->spike((int) $total, (int) ($previous[$categoryId] ?? 0), $threshold, $minimum);
            if ($percent !== null) {
                $found[(string) $categoryId] = ['total' => (int) $total, 'previous' => (int) ($previous[$categoryId] ?? 0), 'percent' => $percent];
            }
        }
        if ($found === []) {
            return [];
        }

        $names = PublicFeedbackCategory::query()->whereIn('id', array_keys($found))->get(['id', 'name_en', 'name_am'])->keyBy(fn ($c): string => (string) $c->id);
        uasort($found, fn (array $a, array $b): int => $b['percent'] <=> $a['percent']);

        $items = [];
        foreach (array_slice($found, 0, 5, true) as $categoryId => $data) {
            $items[] = $this->item('category_increase', 'warning', $data['total'], [
                'category_en' => $names[$categoryId]->name_en ?? null,
                'category_am' => $names[$categoryId]->name_am ?? null,
                'previous' => $data['previous'],
                'percent' => $data['percent'],
            ], ['category_id' => $categoryId]);
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function repeated(SituationRoomScope $scope, SituationRoomFilters $filters, int $threshold): array
    {
        $rows = $this->voice->base($scope, $filters, $filters->start, $filters->end)->toBase()
            ->whereNotNull(self::T.'.category_id')
            ->whereIn(self::T.'.status', SituationRoomMetrics::UNRESOLVED_STATUSES)
            ->selectRaw(self::T.'.organization_id AS organization_id, '.self::T.'.category_id AS category_id, COUNT(*) AS total')
            ->groupBy(self::T.'.organization_id', self::T.'.category_id')
            ->havingRaw('COUNT(*) >= ?', [$threshold])
            ->orderByDesc('total')
            ->limit(5)
            ->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $organizations = Organization::query()->whereIn('id', $rows->pluck('organization_id')->all())->get(['id', 'name_en', 'name_am'])->keyBy(fn ($o): string => (string) $o->id);
        $categories = PublicFeedbackCategory::query()->whereIn('id', $rows->pluck('category_id')->all())->get(['id', 'name_en', 'name_am'])->keyBy(fn ($c): string => (string) $c->id);

        return $rows->map(fn ($row): array => $this->item('repeated', 'warning', (int) $row->total, [
            'organization_id' => (string) $row->organization_id,
            'organization_en' => $organizations[(string) $row->organization_id]->name_en ?? null,
            'organization_am' => $organizations[(string) $row->organization_id]->name_am ?? null,
            'category_en' => $categories[(string) $row->category_id]->name_en ?? null,
            'category_am' => $categories[(string) $row->category_id]->name_am ?? null,
        ], ['category_id' => (string) $row->category_id]))->all();
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, string>  $filters
     * @return array{code: string, tone: string, count: int, params: array<string, mixed>, filters: array<string, string>}
     */
    private function item(string $code, string $tone, int $count, array $params, array $filters): array
    {
        return ['code' => $code, 'tone' => $tone, 'count' => $count, 'params' => $params, 'filters' => $filters];
    }
}
