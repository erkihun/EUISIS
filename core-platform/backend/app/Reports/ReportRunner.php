<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\User;
use App\Support\CoreDates;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Lang;

/**
 * Shapes a report's rows for people (ADR 0011): only the columns the reader
 * may see (sensitive ones only when asked for, permitted and with a
 * purpose), headings, status labels and dates in the chosen language, and
 * spreadsheet cells that cannot run as formulas.
 */
final class ReportRunner
{
    public function __construct(private readonly ReportCatalog $catalog) {}

    /**
     * @param  list<string>|null  $requested  the columns asked for (null = all non-sensitive)
     * @return array<string, array{0: string, 1: string, 2?: bool}> key => definition
     */
    public function columns(string $report, User $user, ?array $requested, bool $sensitive): array
    {
        $definition = ReportCatalog::definitions()[$report] ?? abort(404);
        $mayShowSensitive = $sensitive && $user->can('reports.export_sensitive');
        $columns = array_filter($definition['columns'], fn (array $c): bool => ! ($c[2] ?? false) || $mayShowSensitive);
        if ($requested !== null && $requested !== []) {
            $columns = array_intersect_key($columns, array_flip($requested));
        }

        return $columns;
    }

    /** @param array<string, mixed> $filters */
    public function preview(string $report, User $user, array $filters, string $locale, int $perPage = 25): array
    {
        $columns = $this->columns($report, $user, null, false);
        $page = $this->catalog->query($report, $user, $filters)->paginate($perPage)->withQueryString();

        return [
            'columns' => collect($columns)->map(fn (array $c, string $key): array => ['key' => $key, 'label' => $locale === 'am' ? $c[1] : $c[0]])->values(),
            'rows' => collect($page->items())->map(fn (object $row): array => $this->row($report, $row, $columns, $locale))->values(),
            'meta' => $this->meta($page),
        ];
    }

    /**
     * @param  array<string, array{0: string, 1: string, 2?: bool}>  $columns
     * @return array<string, string>
     */
    public function row(string $report, object $row, array $columns, string $locale): array
    {
        $definition = ReportCatalog::definitions()[$report];
        $values = $definition['values'] ?? [];
        $dates = array_flip($definition['dates'] ?? []);
        $out = [];
        foreach ($columns as $key => $column) {
            $value = $row->{$key} ?? null;
            if ($key === 'coverage' && $value === null && isset($row->active_employees)) {
                $value = (int) $row->active_employees === 0 ? '' : number_format(100 * (int) $row->with_card / (int) $row->active_employees, 1);
            }
            $out[$key] = match (true) {
                $value === null => '',
                isset($dates[$key]) => CoreDates::display($value, $locale, (bool) ($definition['datetime'] ?? false)),
                isset($values[$key]) => $this->label($values[$key], (string) $value, $locale),
                is_bool($value) || in_array($key, ['is_active', 'mfa'], true) => $this->label('boolean', ((bool) $value) ? 'yes' : 'no', $locale),
                $key === 'check_code' => $this->label('check', (string) $value, $locale),
                is_float($value) => number_format($value, 1),
                default => (string) $value,
            };
        }

        return $out;
    }

    /** A value that a spreadsheet would run as a formula is kept as text. */
    public static function safeCell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    private function label(string $domain, string $value, string $locale): string
    {
        $key = "oversight.values.{$domain}.{$value}";

        return Lang::has($key, $locale) ? (string) __($key, [], $locale) : $value;
    }

    /** @return array<string, int|null> */
    private function meta(LengthAwarePaginator $page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
            'from' => $page->firstItem(), 'to' => $page->lastItem()];
    }
}
