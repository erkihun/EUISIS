<?php

declare(strict_types=1);

namespace App\Services\ServiceMonitoring;

use App\Enums\OrganizationStatus;
use App\Enums\ServiceMonitoring\SubmissionStatus;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\ServiceMonitoringForm;
use App\Models\ServiceMonitoringPeriod;
use App\Models\ServiceMonitoringSubmission;
use App\Models\User;
use App\Services\OrganizationScope\OrganizationScopeService;
use App\Support\ServiceMonitoring\FormSchema;

/**
 * The consolidated report of one form for one period (docs/service-monitoring.md §Summary).
 *
 * Nothing here is typed in by hand. Fixed-row tables add up per organization
 * type, so the breakdown follows whichever types the form is assigned to
 * (sub-city, woreda, bureau, agency …) instead of a hard-coded "sub-city /
 * city" pair. Row-entry tables list every institution's rows with the
 * institution named. Only the viewer's organization scope is counted.
 *
 * Reports filled on an earlier version of the form are read through the
 * selected version's layout: cells are matched by key, and cells the layout
 * no longer has are left out.
 */
final readonly class ServiceMonitoringSummaryService
{
    /** Rows listed per row-entry table; the full set is in each institution's report. */
    public const MAX_LISTED_ROWS = 2000;

    public function __construct(private OrganizationScopeService $scope) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $viewer, ServiceMonitoringForm $form, ServiceMonitoringPeriod $period, ?string $organizationTypeId, bool $acceptedOnly): array
    {
        $schema = FormSchema::from($form->schema);
        $typeIds = $form->organizationTypes()->pluck('organization_types.id')->map(static fn ($id): string => (string) $id)->all();
        if ($organizationTypeId !== null) {
            $typeIds = array_values(array_intersect($typeIds, [$organizationTypeId]));
        }

        $types = OrganizationType::query()->whereIn('id', $typeIds)->orderBy('level_order')->orderBy('sort_order')->get(['id', 'name_am', 'name_en']);

        $expected = $this->scope->applyOrganizationScope(
            Organization::query()->whereIn('organization_type_id', $typeIds)->where('status', OrganizationStatus::Active->value),
            $viewer,
            'id',
        );

        $statuses = $acceptedOnly ? [SubmissionStatus::Accepted->value] : [SubmissionStatus::Submitted->value, SubmissionStatus::Accepted->value];

        $submissions = $this->scope->applyOrganizationScope(
            ServiceMonitoringSubmission::query()
                ->where('form_code', $form->code)
                ->where('period_id', $period->getKey())
                ->whereHas('organization', static fn ($q) => $q->whereIn('organization_type_id', $typeIds)),
            $viewer,
        );

        $statusCounts = (clone $submissions)->toBase()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')->map(static fn ($n): int => (int) $n)->all();

        $tables = [];
        foreach ($schema->tables() as $block) {
            $tables[$block['key']] = ['block' => $block, 'fixed' => [], 'listed' => [], 'listed_count' => 0, 'totals' => []];
        }
        $texts = [];
        foreach ($schema->blocks() as $block) {
            if ($block['type'] === 'text') {
                $texts[$block['key']] = ['key' => $block['key'], 'label' => $block['label'], 'answers' => []];
            }
        }

        $reported = (clone $submissions)
            ->whereIn('status', $statuses)
            ->with('organization:id,name_am,name_en,organization_type_id')
            ->orderBy('organization_id');

        foreach ($reported->lazy(200) as $submission) {
            $organization = $submission->organization;
            $typeId = (string) $organization->organization_type_id;
            $institution = ['id' => $organization->id, 'name_am' => $organization->name_am, 'name_en' => $organization->name_en, 'type_id' => $typeId];
            $data = is_array($submission->data) ? $submission->data : [];

            foreach ($tables as $key => &$table) {
                $block = $table['block'];
                $answer = is_array($data[$key] ?? null) ? $data[$key] : [];

                if ($block['row_mode'] === 'fixed') {
                    foreach ($block['rows'] as $row) {
                        $cells = is_array($answer[$row['key']] ?? null) ? $answer[$row['key']] : [];
                        foreach ($this->numericCells($block, $cells) as $column => $value) {
                            $table['fixed'][$row['key']][$typeId][$column] = ($table['fixed'][$row['key']][$typeId][$column] ?? 0) + $value;
                            $table['fixed'][$row['key']]['all'][$column] = ($table['fixed'][$row['key']]['all'][$column] ?? 0) + $value;
                        }
                    }

                    continue;
                }

                foreach (array_is_list($answer) ? $answer : [] as $cells) {
                    if (! is_array($cells)) {
                        continue;
                    }
                    foreach ($this->numericCells($block, $cells) as $column => $value) {
                        $table['totals'][$column] = ($table['totals'][$column] ?? 0) + $value;
                    }
                    $table['listed_count']++;
                    if (count($table['listed']) < self::MAX_LISTED_ROWS) {
                        $table['listed'][] = ['institution' => $institution, 'cells' => $this->layoutCells($block, $cells)];
                    }
                }
            }
            unset($table);

            foreach ($texts as $key => &$text) {
                $answer = is_string($data[$key] ?? null) ? trim($data[$key]) : '';
                if ($answer !== '') {
                    $text['answers'][] = ['institution' => $institution, 'text' => $answer];
                }
            }
            unset($text);
        }

        $expectedCount = (clone $expected)->count();
        $reportedIds = (clone $submissions)->whereIn('status', $statuses)->select('organization_id');

        return [
            'types' => $types->map(static fn (OrganizationType $t): array => ['id' => $t->id, 'name_am' => $t->name_am, 'name_en' => $t->name_en])->values()->all(),
            'coverage' => [
                'expected' => $expectedCount,
                'by_status' => $statusCounts,
                'reported' => array_sum(array_intersect_key($statusCounts, array_flip($statuses))),
                'missing' => (clone $expected)->whereNotIn('id', $reportedIds)->orderBy('name_am')->limit(100)->get(['id', 'name_am', 'name_en', 'organization_type_id'])
                    ->map(static fn (Organization $o): array => ['id' => $o->id, 'name_am' => $o->name_am, 'name_en' => $o->name_en, 'type_id' => (string) $o->organization_type_id])->all(),
            ],
            'tables' => array_values(array_map(static fn (array $t): array => [
                'key' => $t['block']['key'],
                'fixed' => (object) $t['fixed'],
                'listed' => $t['listed'],
                'listed_count' => $t['listed_count'],
                'totals' => (object) $t['totals'],
            ], $tables)),
            'texts' => array_values($texts),
        ];
    }

    /**
     * Numeric contributions of a row: numbers and sums as they are, a check mark as 1.
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $cells
     * @return array<string, int|float>
     */
    private function numericCells(array $block, array $cells): array
    {
        $values = [];
        foreach ($block['columns'] as $column) {
            $cell = $cells[$column['key']] ?? null;
            $values[$column['key']] = match ($column['type']) {
                'number', 'sum' => is_int($cell) || is_float($cell) ? $cell : 0,
                'check' => $cell === true ? 1 : 0,
                default => null,
            };
        }

        return array_filter($values, static fn ($v): bool => $v !== null);
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $cells
     * @return array<string, mixed>
     */
    private function layoutCells(array $block, array $cells): array
    {
        $out = [];
        foreach ($block['columns'] as $column) {
            $out[$column['key']] = $cells[$column['key']] ?? null;
        }

        return $out;
    }
}
