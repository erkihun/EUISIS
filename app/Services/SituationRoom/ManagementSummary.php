<?php

declare(strict_types=1);

namespace App\Services\SituationRoom;

/**
 * A few plain sentences that say what the numbers say, built from fixed
 * rules — no language model, no reading of complaint text. Each sentence is a
 * code plus parameters; the page words it in English or Amharic. Wording is
 * neutral ("more than", "fewer than"), and a comparison is made only when the
 * previous period had submissions to compare against.
 */
final class ManagementSummary
{
    /**
     * @param  array{current: array<string, mixed>, previous: array<string, mixed>}  $kpis
     * @param  array{rows: list<array<string, mixed>>, own: array<string, int>}  $institutions
     * @return list<array{code: string, params: array<string, mixed>}>
     */
    public function sentences(array $kpis, array $institutions): array
    {
        $current = $kpis['current'];
        $previous = $kpis['previous'];

        if ((int) ($current['total'] ?? 0) === 0) {
            return [['code' => 'no_submissions', 'params' => []]];
        }

        $sentences = [[
            'code' => 'totals',
            'params' => [
                'total' => (int) $current['total'],
                'feedback' => (int) $current['feedback'],
                'suggestion' => (int) $current['suggestion'],
                'complaint' => (int) $current['complaint'],
            ],
        ]];

        foreach (SituationRoomMetrics::TYPES as $type) {
            $now = (int) ($current[$type] ?? 0);
            $before = (int) ($previous[$type] ?? 0);
            if ($before === 0 || $now === $before) {
                continue;
            }
            $sentences[] = ['code' => $now > $before ? 'type_more' : 'type_fewer', 'params' => ['type' => $type, 'current' => $now, 'previous' => $before]];
        }

        $topInstitution = collect($institutions['rows'])->sortByDesc('unresolved_complaints')->first(fn (array $row): bool => (int) $row['unresolved_complaints'] > 0);
        if ($topInstitution !== null && count($institutions['rows']) > 1) {
            $sentences[] = ['code' => 'most_unresolved_complaints', 'params' => ['institution_en' => $topInstitution['name_en'], 'institution_am' => $topInstitution['name_am'], 'count' => (int) $topInstitution['unresolved_complaints']]];
        }

        if ((int) ($current['unrouted'] ?? 0) > 0) {
            $sentences[] = ['code' => 'unrouted', 'params' => ['count' => (int) $current['unrouted']]];
        }

        if (($current['avg_rating'] ?? null) !== null) {
            $sentences[] = ['code' => 'average_rating', 'params' => ['average' => $current['avg_rating']]];
        }

        return $sentences;
    }
}
