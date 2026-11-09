<?php

declare(strict_types=1);

namespace App\Services\SituationRoom;

use App\Services\Calendar\CalendarService;
use Carbon\CarbonImmutable;

/**
 * Turns a computed category payload into export blocks: one block per panel,
 * every label resolved in the export's language, dates in that language's
 * calendar (English: Gregorian, Amharic: Ethiopian). The export is built
 * from the very payload the screen shows, so it carries the same scope, the
 * same filters and the same suppressions — never more.
 */
final class SituationExportService
{
    public const FORMATS = ['csv', 'xlsx', 'pdf'];

    public function __construct(private readonly CalendarService $calendar) {}

    /**
     * @param  array{generated_at: string, payload: array{panels: list<array<string, mixed>>}}  $result
     * @return array{title: string, period: string, scope: string, generated_at: string, blocks: list<array{title: string, notice: ?string, headings: list<string>, rows: list<list<string|int|float|null>>}>}
     */
    public function document(SituationCategory $category, SituationContext $context, array $result, string $scopeLabel, string $locale): array
    {
        $period = $context->period();
        $blocks = [];

        foreach ($result['payload']['panels'] as $panel) {
            $blocks[] = $this->block($panel, $locale);
        }

        return [
            'title' => SituationLabels::text("categories.{$category->value}.title", $locale),
            'period' => ($this->calendar->formatDate($period->from->toDateString(), $locale) ?? $period->from->toDateString())
                .' – '.($this->calendar->formatDate($period->to->toDateString(), $locale) ?? $period->to->toDateString()),
            'scope' => $scopeLabel,
            'generated_at' => $this->calendar->formatDateTime(CarbonImmutable::parse($result['generated_at'])->toDateTimeString(), $locale) ?? $result['generated_at'],
            'blocks' => $blocks,
        ];
    }

    /**
     * The document as one long sheet: panel, item, measure, value.
     *
     * @return array{headings: list<string>, rows: list<list<string|int|float|null>>}
     */
    public function sheet(array $document, string $locale): array
    {
        $rows = [];
        foreach ($document['blocks'] as $block) {
            if ($block['notice'] !== null) {
                $rows[] = [$block['title'], $block['notice'], '', null];

                continue;
            }
            foreach ($block['rows'] as $row) {
                $item = (string) ($row[0] ?? '');
                foreach (array_slice($block['headings'], 1, null, true) as $i => $heading) {
                    $rows[] = [$block['title'], $item, $heading, $row[$i] ?? null];
                }
            }
        }

        return [
            'headings' => array_map(fn (string $k): string => SituationLabels::text("export.{$k}", $locale), ['panel', 'item', 'measure', 'value']),
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $panel
     * @return array{title: string, notice: ?string, headings: list<string>, rows: list<list<string|int|float|null>>}
     */
    private function block(array $panel, string $locale): array
    {
        $title = SituationLabels::text((string) $panel['title'], $locale);
        $data = $panel['data'];

        return match ($panel['type']) {
            'notice' => ['title' => $title, 'notice' => SituationLabels::text('reasons.'.$panel['reason'].'.title', $locale), 'headings' => [], 'rows' => []],
            'kpis' => [
                'title' => $title,
                'notice' => null,
                'headings' => [SituationLabels::text('export.measure', $locale), SituationLabels::text('export.value', $locale)],
                'rows' => array_map(fn (array $item): array => [
                    SituationLabels::text((string) $item['label'], $locale).(isset($item['unit']) ? ' ('.$item['unit'].')' : ''),
                    ($item['state'] ?? null) === 'not_configured' ? SituationLabels::text('reasons.CONFIGURATION_REQUIRED.title', $locale) : ($item['value'] ?? null),
                ], $data['items']),
            ],
            'table' => [
                'title' => $title,
                'notice' => null,
                'headings' => array_map(fn (array $c): string => SituationLabels::resolve($c['label'], $locale), $data['columns']),
                'rows' => array_map(fn (array $row): array => array_map(
                    fn (array $c) => $this->cell($row[$c['key']] ?? null, $c['type'], $locale),
                    $data['columns'],
                ), $data['rows']),
            ],
            default => [
                'title' => $title,
                'notice' => null,
                'headings' => [SituationLabels::text('export.item', $locale), ...array_map(fn (array $s): string => SituationLabels::resolve($s['label'], $locale), $data['series'])],
                'rows' => array_map(fn ($category, int $i): array => [
                    $this->categoryLabel($category, $locale),
                    ...array_map(fn (array $s) => $s['values'][$i] ?? null, $data['series']),
                ], $data['categories'], array_keys($data['categories'])),
            ],
        };
    }

    private function categoryLabel(mixed $category, string $locale): string
    {
        if (is_string($category) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $category) === 1) {
            return $this->calendar->formatDate($category, $locale) ?? $category;
        }

        return SituationLabels::resolve($category, $locale);
    }

    private function cell(mixed $value, string $type, string $locale): string|int|float|null
    {
        return match ($type) {
            'label' => SituationLabels::resolve($value, $locale),
            'date' => $value === null ? null : ($this->calendar->formatDate((string) $value, $locale) ?? (string) $value),
            'number', 'percent' => is_numeric($value) ? $value + 0 : null,
            default => $value === null ? null : (string) $value,
        };
    }
}
