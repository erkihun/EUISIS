<?php

declare(strict_types=1);

namespace App\Services\SituationRoom;

/**
 * One block of a Situation Room page: a KPI row, a chart, a table, or an
 * honest notice that the figure cannot be shown.
 *
 * Every panel names what it shows (title), what it means (description key),
 * where it comes from (source), and how to look deeper (href). Payloads are
 * compact aggregates — never models, never personal data.
 *
 * Notice reasons: NO_DATA (the source has none for this window),
 * NOT_CONFIGURED (a policy value such as a threshold is not set),
 * NEEDS_DATA (the system does not record what the metric needs),
 * NO_PERMISSION, ERROR. Zero is never shown for an unavailable source.
 */
final readonly class SituationPanel
{
    public const CHARTS = ['bar', 'hbar', 'stacked', 'line', 'donut'];

    /** Colour schemes a chart may ask for instead of the categorical default. */
    public const PALETTES = ['diverging'];

    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        public string $key,
        public string $type,
        public string $title,
        public ?string $description,
        public string $source,
        public array $data,
        public ?string $href = null,
        public ?string $reason = null,
        public int $span = 1,
    ) {}

    /**
     * @param  list<array{key: string, label: array<string, ?string>, value: int|float|null, unit?: string, definition?: string, previous?: int|float|null, href?: string|null, state?: string}>  $items
     */
    public static function kpis(string $key, string $title, string $source, array $items, ?string $description = null): self
    {
        return new self($key, 'kpis', $title, $description, $source, ['items' => $items], span: 3);
    }

    /**
     * @param  list<array{key: string, label: array<string, ?string>}>  $categories
     * @param  list<array{key: string, label: array<string, ?string>, values: list<int|float>}>  $series
     */
    public static function chart(string $key, string $type, string $title, string $description, string $source, array $categories, array $series, ?string $href = null, int $span = 1, ?string $unit = null, ?string $palette = null): self
    {
        $total = array_sum(array_map(fn (array $s): float => (float) array_sum(array_filter($s['values'], 'is_numeric')), $series));
        // A null is a count withheld by the small-group rule: something was recorded, so this is not "no data".
        $withheld = array_filter($series, fn (array $s): bool => in_array(null, $s['values'], true)) !== [];

        if ($categories === [] || ($total == 0 && ! $withheld)) {
            return self::notice($key, $title, $description, $source, 'NO_DATA', $span);
        }

        // `palette: diverging` marks series that are the ordered points of one scale (PALETTES), drawn low → high.
        $data = ['categories' => $categories, 'series' => $series, 'unit' => $unit] + ($palette !== null ? ['palette' => $palette] : []);

        return new self($key, $type, $title, $description, $source, $data, $href, span: $span);
    }

    /**
     * @param  list<array{key: string, label: array<string, ?string>, numeric?: bool}>  $columns
     * @param  list<array{key: string, cells: array<string, mixed>, href?: string|null}>  $rows
     * @param  array<string, mixed>|null  $pagination
     */
    public static function table(string $key, string $title, string $description, string $source, array $columns, array $rows, ?array $pagination = null, ?string $href = null, int $span = 2): self
    {
        if ($rows === []) {
            return self::notice($key, $title, $description, $source, 'NO_DATA', $span);
        }

        return new self($key, 'table', $title, $description, $source, ['columns' => $columns, 'rows' => $rows, 'pagination' => $pagination], $href, span: $span);
    }

    public static function notice(string $key, string $title, ?string $description, string $source, string $reason, int $span = 1): self
    {
        return new self($key, 'notice', $title, $description, $source, [], reason: $reason, span: $span);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type,
            'title' => $this->title,
            'description' => $this->description,
            'source' => $this->source,
            'data' => $this->data,
            'href' => $this->href,
            'reason' => $this->reason,
            'span' => $this->span,
        ];
    }
}
