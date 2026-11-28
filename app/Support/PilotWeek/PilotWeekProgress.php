<?php

declare(strict_types=1);

namespace App\Support\PilotWeek;

use App\Services\Calendar\EthiopianCalendarService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Figures for the temporary pilot week dashboard.
 *
 * Counts only: how many units, positions, employees, users, services and
 * registry offices each institution has, and how many of them were added in
 * the week shown. No name, number or other detail of a person is read.
 *
 * The plan it is measured against is the five-day training programme:
 * units, then positions, then employees, then users and the institution's
 * settings (services, letter registry office).
 */
final class PilotWeekProgress
{
    /** The plan steps, in the order the programme registers them. */
    public const STEPS = ['units', 'positions', 'employees', 'users', 'services', 'registry'];

    public function __construct(private readonly EthiopianCalendarService $calendar) {}

    /** The secret part of the dashboard link. */
    public static function token(): string
    {
        $configured = trim((string) config('pilot_dashboard.token'));

        return $configured !== ''
            ? $configured
            : substr(hash_hmac('sha256', 'pilot-week-dashboard', (string) config('app.key')), 0, 24);
    }

    /** Whether the page answers today. */
    public static function isOpen(): bool
    {
        if (! config('pilot_dashboard.enabled')) {
            return false;
        }

        $until = trim((string) config('pilot_dashboard.until'));

        return $until === '' || CarbonImmutable::now()->toDateString() <= $until;
    }

    /**
     * @param  string|null  $week  any date inside the week to show (Y-m-d); the current week when null or unreadable
     * @return array<string, mixed>
     */
    public function forWeek(?string $week = null): array
    {
        $anchor = $this->parse($week) ?? CarbonImmutable::now();
        $from = $anchor->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        $to = $from->addDays(7);

        return Cache::remember(
            'pilot-week-dashboard:'.$from->toDateString().':'.md5(implode(',', (array) config('pilot_dashboard.organization_codes'))),
            max(0, (int) config('pilot_dashboard.cache_seconds')),
            fn (): array => $this->build($from, $to),
        );
    }

    /** @return array<string, mixed> */
    private function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $codes = (array) config('pilot_dashboard.organization_codes');

        $organizations = DB::table('organizations')
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->where('is_demo', false)
            ->when($codes !== [], fn ($query) => $query->whereIn('code', $codes))
            ->orderBy('sort_order')->orderBy('code')
            ->get(['id', 'code', 'name_am', 'name_en']);

        $ids = $organizations->pluck('id')->all();
        $counts = [
            'units' => $this->counts('organization_units', 'organization_id', $ids, $from, $to, softDeletes: true),
            'positions' => $this->counts('positions', 'organization_id', $ids, $from, $to, softDeletes: true),
            'employees' => $this->counts('employee_assignments', 'organization_id', $ids, $from, $to, where: ['is_current' => true]),
            'users' => $this->counts('users', 'default_organization_id', $ids, $from, $to, where: ['is_demo' => false]),
            'services' => $this->counts('services', 'organization_id', $ids, $from, $to, softDeletes: true),
            'registry' => $this->counts('letter_registry_offices', 'organization_id', $ids, $from, $to),
        ];

        $rows = [];
        $totals = array_fill_keys(self::STEPS, ['total' => 0, 'week' => 0]);

        foreach ($organizations as $organization) {
            $steps = [];
            $done = 0;

            foreach (self::STEPS as $step) {
                $total = (int) ($counts[$step]['total'][$organization->id] ?? 0);
                $week = (int) ($counts[$step]['week'][$organization->id] ?? 0);
                $steps[$step] = ['total' => $total, 'week' => $week];
                $totals[$step]['total'] += $total;
                $totals[$step]['week'] += $week;
                $done += $total > 0 ? 1 : 0;
            }

            $rows[] = [
                'code' => (string) $organization->code,
                'name' => (string) ($organization->name_am ?: $organization->name_en),
                'steps' => $steps,
                'done' => $done,
                'percent' => (int) round($done / count(self::STEPS) * 100),
                'week' => array_sum(array_column($steps, 'week')),
            ];
        }

        // The institutions furthest along first; ties by this week's work.
        usort($rows, static fn (array $a, array $b): int => [$b['done'], $b['week']] <=> [$a['done'], $a['week']]);

        return [
            'from' => $from->toDateString(),
            'to' => $to->subDay()->toDateString(),
            'label' => $this->ethiopian($from).' – '.$this->ethiopian($to->subDay()),
            'previous' => $from->subDays(7)->toDateString(),
            'next' => $to->lessThanOrEqualTo(CarbonImmutable::now()) ? $to->toDateString() : null,
            'isCurrent' => CarbonImmutable::now()->betweenIncluded($from, $to->subSecond()),
            'organizations' => $rows,
            'totals' => $totals,
            'complete' => count(array_filter($rows, static fn (array $row): bool => $row['done'] === count(self::STEPS))),
            'started' => count(array_filter($rows, static fn (array $row): bool => $row['done'] > 0)),
            'days' => $this->days($ids, $from),
            'generatedAt' => CarbonImmutable::now()->format('H:i'),
        ];
    }

    /**
     * Total and this-week row counts of one table, per organization.
     *
     * @param  list<string>  $ids
     * @param  array<string, mixed>  $where
     * @return array{total: array<string, int>, week: array<string, int>}
     */
    private function counts(string $table, string $column, array $ids, CarbonImmutable $from, CarbonImmutable $to, bool $softDeletes = false, array $where = []): array
    {
        if ($ids === []) {
            return ['total' => [], 'week' => []];
        }

        $base = fn () => DB::table($table)
            ->whereIn($column, $ids)
            ->when($softDeletes, fn ($query) => $query->whereNull('deleted_at'))
            ->where($where)
            ->groupBy($column)
            ->selectRaw($column.' as organization_id, count(*) as aggregate');

        return [
            'total' => $base()->pluck('aggregate', 'organization_id')->map(static fn ($n): int => (int) $n)->all(),
            'week' => $base()->where('created_at', '>=', $from)->where('created_at', '<', $to)
                ->pluck('aggregate', 'organization_id')->map(static fn ($n): int => (int) $n)->all(),
        ];
    }

    /**
     * Records added on each day of the week, all kinds together.
     *
     * @param  list<string>  $ids
     * @return list<array{date: string, name: string, count: int, isToday: bool}>
     */
    private function days(array $ids, CarbonImmutable $from): array
    {
        $names = $this->calendar->ethiopianWeekdayNames('am');
        $sources = [
            ['organization_units', 'organization_id', true, []],
            ['positions', 'organization_id', true, []],
            ['employee_assignments', 'organization_id', false, ['is_current' => true]],
            ['users', 'default_organization_id', false, ['is_demo' => false]],
            ['services', 'organization_id', true, []],
            ['letter_registry_offices', 'organization_id', false, []],
        ];
        $days = [];

        for ($i = 0; $i < 7; $i++) {
            $day = $from->addDays($i);
            $count = 0;

            if ($ids !== [] && $day->lessThanOrEqualTo(CarbonImmutable::now())) {
                foreach ($sources as [$table, $column, $softDeletes, $where]) {
                    $count += DB::table($table)
                        ->whereIn($column, $ids)
                        ->when($softDeletes, fn ($query) => $query->whereNull('deleted_at'))
                        ->where($where)
                        ->where('created_at', '>=', $day)
                        ->where('created_at', '<', $day->addDay())
                        ->count();
                }
            }

            $days[] = [
                'date' => $day->toDateString(),
                'name' => (string) ($names[$day->dayOfWeek] ?? ''),
                'count' => $count,
                'isToday' => $day->isToday(),
            ];
        }

        return $days;
    }

    private function ethiopian(CarbonImmutable $date): string
    {
        $eth = $this->calendar->gregorianToEthiopian($date->year, $date->month, $date->day);

        return $this->calendar->formatEthiopianDate($eth['year'], $eth['month'], $eth['day']);
    }

    private function parse(?string $week): ?CarbonImmutable
    {
        if ($week === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $week) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $week) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
