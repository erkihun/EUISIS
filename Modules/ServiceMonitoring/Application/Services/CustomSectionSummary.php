<?php

declare(strict_types=1);

namespace Modules\ServiceMonitoring\Application\Services;

use App\Models\OrganizationType;
use App\Models\User;
use Illuminate\Support\Collection;
use Modules\ServiceMonitoring\Domain\Enums\FieldAggregation;
use Modules\ServiceMonitoring\Domain\Enums\ReportRange;
use Modules\ServiceMonitoring\Domain\Enums\SubmissionStatus;
use Modules\ServiceMonitoring\Domain\Forms\FormSchema;
use Modules\ServiceMonitoring\Domain\Models\FiscalYear;
use Modules\ServiceMonitoring\Domain\Models\FormTemplate;
use Modules\ServiceMonitoring\Domain\Models\Submission;

/**
 * The consolidated figures of a form template's additional sections over a
 * report range (docs/service-monitoring.md §Form templates), by the rules of
 * the official sections (D3, D4): only accepted revisions count; a `flow`
 * column is summed over the quarters of the range, a `stock` column takes
 * the institution's latest quarter with an accepted report.
 *
 * Figures are broken down by organization type, whatever types the template
 * is assigned to; no administrative level is built in. Only reports inside
 * the viewer's scope are read.
 */
final readonly class CustomSectionSummary
{
    /** Rows listed per row-entry table; the totals include every row. */
    public const MAX_LISTED_ROWS = 2000;

    public function __construct(private ServiceMonitoringAccess $access) {}

    /** @return array<string, mixed> */
    public function build(User $viewer, FormTemplate $template, FiscalYear $year, ReportRange $range, int $quarter): array
    {
        $schema = $template->contentRules()->custom();
        $quarters = $range->quarters($quarter);

        $submissions = $this->access->scopeSubmissions(Submission::query(), $viewer)
            ->whereIn('form_template_id', FormTemplate::query()->where('code', $template->code)->select('id'))
            ->where('status', SubmissionStatus::Accepted->value)
            ->whereHas('period', static fn ($q) => $q->where('fiscal_year_id', $year->id)->whereIn('quarter', $quarters))
            ->with(['period:id,quarter', 'organization:id,name_am,name_en,organization_type_id'])
            ->get(['id', 'period_id', 'participant_id', 'organization_id', 'custom_data']);

        // Per institution, its accepted reports ordered by quarter: the last one carries the stock figures.
        $byParticipant = $submissions->sortBy(static fn (Submission $s): int => $s->period->quarter)->groupBy('participant_id');
        $typeIds = $submissions->pluck('organization.organization_type_id')->filter()->unique()->values();
        $types = OrganizationType::query()->whereIn('id', $typeIds)->orderBy('level_order')->orderBy('name_en')->get(['id', 'name_am', 'name_en']);

        $tables = [];
        foreach ($schema->tables() as $block) {
            $tables[] = $this->table($block, $byParticipant);
        }

        $texts = [];
        foreach ($schema->blocks() as $block) {
            if ($block['type'] !== 'text') {
                continue;
            }
            $answers = [];
            foreach ($submissions->sortBy(static fn (Submission $s): int => $s->period->quarter) as $submission) {
                $text = trim((string) ($submission->custom_data[$block['key']] ?? ''));
                if ($text !== '') {
                    $answers[] = ['institution' => $this->institution($submission), 'quarter' => $submission->period->quarter, 'text' => $text];
                }
            }
            $texts[] = ['key' => $block['key'], 'answers' => $answers];
        }

        return [
            'types' => $types->map(static fn (OrganizationType $t): array => ['id' => $t->id, 'name_am' => $t->name_am, 'name_en' => $t->name_en])->all(),
            'quarters' => $quarters,
            'reports' => $submissions->count(),
            'institutions' => $byParticipant->count(),
            'tables' => $tables,
            'texts' => $texts,
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  Collection<string, Collection<int, Submission>>  $byParticipant
     * @return array<string, mixed>
     */
    private function table(array $block, $byParticipant): array
    {
        $numeric = array_values(array_filter($block['columns'], static fn (array $c): bool => in_array($c['type'], ['number', 'sum', 'check'], true)));
        $fixed = [];
        $listed = [];
        $listedCount = 0;
        $totals = [];

        foreach ($byParticipant as $reports) {
            $latest = $reports->last();
            $typeId = (string) $latest->organization?->organization_type_id;

            if ($block['row_mode'] === 'fixed') {
                foreach ($block['rows'] as $row) {
                    foreach ($numeric as $column) {
                        $value = $this->aggregate($reports, $block['key'], $column, static fn (array $answer): array => [is_array($answer[$row['key']] ?? null) ? $answer[$row['key']] : []]);
                        $fixed[$row['key']][$typeId][$column['key']] = ($fixed[$row['key']][$typeId][$column['key']] ?? 0) + $value;
                        $fixed[$row['key']]['all'][$column['key']] = ($fixed[$row['key']]['all'][$column['key']] ?? 0) + $value;
                    }
                }

                continue;
            }

            foreach ($numeric as $column) {
                $totals[$column['key']] = ($totals[$column['key']] ?? 0)
                    + $this->aggregate($reports, $block['key'], $column, static fn (array $answer): array => array_is_list($answer) ? $answer : []);
            }
            // Rows are listed from every quarter of the range, newest first per institution.
            foreach ($reports->reverse() as $report) {
                $answer = $report->custom_data[$block['key']] ?? [];
                foreach (is_array($answer) && array_is_list($answer) ? $answer : [] as $cells) {
                    $listedCount++;
                    if (count($listed) < self::MAX_LISTED_ROWS && is_array($cells)) {
                        $listed[] = ['institution' => $this->institution($report), 'quarter' => $report->period->quarter, 'cells' => $cells];
                    }
                }
            }
        }

        return ['key' => $block['key'], 'fixed' => (object) $fixed, 'listed' => $listed, 'listed_count' => $listedCount, 'totals' => (object) $totals];
    }

    /**
     * One institution's figure for a column over its accepted reports in the range.
     *
     * @param  Collection<int, Submission>  $reports  ordered by quarter
     * @param  array<string, mixed>  $column
     * @param  callable(array<mixed>): list<mixed>  $rowsOf  the rows the column is read from in one report's answer to the block
     */
    private function aggregate($reports, string $blockKey, array $column, callable $rowsOf): int|float
    {
        $sumOf = static function (Submission $report) use ($blockKey, $column, $rowsOf): int|float {
            $answer = $report->custom_data[$blockKey] ?? null;
            $total = 0;
            foreach ($rowsOf(is_array($answer) ? $answer : []) as $cells) {
                $cell = is_array($cells) ? ($cells[$column['key']] ?? null) : null;
                $total += $column['type'] === 'check' ? ($cell === true ? 1 : 0) : (is_int($cell) || is_float($cell) ? $cell : 0);
            }

            return $total;
        };

        if (FormSchema::aggregationOf($column) === FieldAggregation::Stock) {
            return $sumOf($reports->last());
        }

        return $reports->reduce(static fn (int|float $carry, Submission $report): int|float => $carry + $sumOf($report), 0);
    }

    /** @return array<string, mixed> */
    private function institution(Submission $submission): array
    {
        return [
            'id' => $submission->organization_id,
            'name_am' => $submission->organization?->name_am,
            'name_en' => $submission->organization?->name_en,
            'type_id' => (string) $submission->organization?->organization_type_id,
        ];
    }
}
