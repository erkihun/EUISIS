<?php

declare(strict_types=1);

namespace App\Services\SituationRoom;

use App\Services\SituationRoom\Analytics\WorkforceQueries;
use Throwable;

/**
 * What the Situation Room landing page says about the rest of the room: one
 * headline per category, the alert rules, the data quality checks, this period
 * against the last, and the institutions with something recorded.
 *
 * Read from the categories' own (cached) payloads, so the landing page can never
 * disagree with the page it links to and a category the viewer may not open is
 * never read. Aggregates only: panels that name people are not looked at.
 */
final readonly class SituationBriefing
{
    /** Figures a launcher card has room for. */
    private const HEADLINES = 2;

    /** Institutions listed side by side before the link to the full table. */
    private const INSTITUTIONS = 8;

    public function __construct(
        private SituationRoomService $room,
        private OrganizationRollup $rollup,
    ) {}

    /** @return array<string, mixed> */
    public function build(SituationContext $context): array
    {
        $panels = [];
        $headlines = [];

        foreach (SituationCategory::visibleFor($context->user) as $category) {
            if ($category === SituationCategory::Overview) {
                continue;
            }

            $byKey = [];
            foreach ($this->room->category($category, $context)['payload']['panels'] as $panel) {
                $byKey[$panel['key']] = $panel;
            }
            $panels[$category->value] = $byKey;
            $headlines[$category->value] = $this->headlines($byKey);
        }

        return [
            'headlines' => $headlines,
            'structure' => $panels['structure']['structure_kpis']['data']['items'] ?? [],
            'attention' => $panels['critical_attention']['attention_kpis']['data']['items'] ?? [],
            'comparison' => $panels['trends']['trends_comparison']['data']['rows'] ?? [],
            'quality' => $panels['data_quality']['quality_checks']['data']['rows'] ?? [],
            'institutions' => $this->institutions($panels['trends']['trends_organizations'] ?? null),
            'placement' => $this->placement($context),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $panels
     * @return list<array<string, mixed>>
     */
    private function headlines(array $panels): array
    {
        foreach ($panels as $panel) {
            if ($panel['type'] === 'kpis') {
                $figures = array_filter($panel['data']['items'], fn (array $item): bool => ($item['state'] ?? null) !== 'not_configured');

                return array_map(fn (array $item): array => [
                    'key' => $item['key'],
                    'label' => $item['label'],
                    'value' => $item['value'],
                    'unit' => $item['unit'] ?? null,
                    'state' => $item['state'] ?? null,
                ], array_slice(array_values($figures), 0, self::HEADLINES));
            }
        }

        return [];
    }

    /**
     * The roll-up rows with something recorded, still in name order: there to read
     * across, not to rank (see TrendsAnalytics).
     *
     * @param  array<string, mixed>|null  $panel
     * @return array{columns: list<array<string, mixed>>, rows: list<array<string, mixed>>, active: int, total: int}|null
     */
    private function institutions(?array $panel): ?array
    {
        if ($panel === null || $panel['type'] !== 'table') {
            return null;
        }

        $active = array_values(array_filter($panel['data']['rows'], function (array $row): bool {
            unset($row['organization']);

            return array_sum($row) > 0;
        }));

        return [
            'columns' => array_values(array_filter($panel['data']['columns'], fn (array $column): bool => $column['key'] !== 'organization')),
            'rows' => array_slice($active, 0, self::INSTITUTIONS),
            'active' => count($active),
            'total' => count($panel['data']['rows']),
        ];
    }

    /**
     * How many of the people in scope sit on the published hierarchy; the rest
     * cannot appear under any institution in a roll-up.
     *
     * @return array{placed: int, total: int}|null
     */
    private function placement(SituationContext $context): ?array
    {
        if (! $context->can(SituationPermissions::WORKFORCE)) {
            return null;
        }

        // Like a panel, one figure failing must not cost the page the rest of its briefing.
        try {
            $byOrganization = WorkforceQueries::headcountByOrganization($context);
            $total = array_sum($byOrganization);
            $unplaced = OrganizationRollup::sum($byOrganization, $this->rollup->for($context->scope))['__unplaced'] ?? 0;

            return ['placed' => $total - $unplaced, 'total' => $total];
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
