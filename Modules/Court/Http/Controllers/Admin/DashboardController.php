<?php

declare(strict_types=1);

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Domain\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    // Cache TTL in seconds — short enough to feel live, long enough to matter
    private const CACHE_TTL = 300; // 5 minutes

    /** The periods the dashboard can show besides all time (`stats` accepts the same). */
    private const RANGES = ['7d', '30d', '90d', '6m', '12m'];

    /**
     * The admin dashboard (React, Court/Dashboard). Without `range` the figures
     * are all time and the chart covers six months, as on the Blade page it
     * replaces; with a range every figure covers that period (the `stats` data).
     *
     * Case statistics (counts, workload by person and team, recent case
     * titles) need cases.view, and service health and storage settings.manage;
     * any other staff member gets the page with neither (null props).
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $canSeeCases = (bool) $user?->hasPermission('cases.view');
        $canSeeSystem = (bool) $user?->hasPermission('settings.manage');

        $dashboard = null;
        if ($canSeeCases) {
            $range = $request->query('range');
            if (is_string($range) && in_array($range, self::RANGES, true)) {
                [$start, $end] = $this->rangeBounds($range);
                $dashboard = $this->fromRange($range, $this->rangeStats($start, $end));
            } else {
                $dashboard = $this->fromAllTime($this->allTime());
            }
        }

        return Inertia::render('Court/Dashboard', [
            'dashboard' => $dashboard,
            'ranges' => self::RANGES,
            'serviceHealth' => $canSeeSystem ? $this->serviceHealth() : null,
            'dataStorage' => $canSeeSystem ? $this->dataStorage() : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function allTime(): array
    {
        return Cache::remember('court.dashboard_index_v4', self::CACHE_TTL, function () {
            $totalCases    = DB::table('court_cases')->count();
            $pendingCases  = DB::table('court_cases')->where('status', 'pending')->count();
            $resolvedCases = DB::table('court_decisions')
                ->where('status', 'published')
                ->whereNotNull('court_case_id')
                ->distinct()
                ->count('court_case_id');
            $activeUsers   = DB::table('court_staff')->where('status', 'active')->count();
            $activeCases   = DB::table('court_cases')->where('status', 'active')->count();

            $recent = DB::table('court_cases')
                ->select('id', 'case_number', 'title', 'status', 'created_at')
                ->orderByDesc('created_at')
                ->limit(8)
                ->get();

            // monthly cases (last 6 months) — single query, build labels in PHP
            $reference = Carbon::now();
            $months = DB::table('court_cases')
                ->selectRaw(\Modules\Court\Application\Support\Sql::yearMonth('created_at')." as ym, COUNT(*) as cnt")
                ->where('created_at', '>=', $reference->copy()->subMonths(5)->startOfMonth())
                ->groupBy('ym')
                ->orderBy('ym')
                ->get();

            // team case counts — single aggregated query
            $teamCaseCounts = DB::table('court_teams as t')
                ->leftJoin('court_cases as c', 'c.assigned_team_id', '=', 't.id')
                ->select('t.id', 't.name', DB::raw('COUNT(c.id) as cases_count'))
                ->groupBy('t.id', 't.name')
                ->orderByDesc('cases_count')
                ->orderBy('t.name')
                ->get();

            // member counts + type breakdown — two queries, merged in PHP (no N+1)
            $memberCaseCounts = DB::table('court_team_user as tu')
                ->join('court_staff as u', 'u.id', '=', 'tu.user_id')
                ->leftJoin('court_teams as t', 't.id', '=', 'tu.team_id')
                ->leftJoin('court_cases as c', function ($join) {
                    $join->on('c.assigned_member_user_id', '=', 'u.id')
                        ->orOn(function ($query) {
                            $query->on('c.assigned_user_id', '=', 'u.id')
                                ->whereNull('c.assigned_member_user_id');
                        });
                })
                ->leftJoin('court_decisions as d', function ($join) {
                    $join->on('d.court_case_id', '=', 'c.id')
                        ->where('d.status', '=', 'published');
                })
                ->select(
                    'u.id', 'u.name', 'u.email', 'u.status', 'u.avatar_path',
                    't.name as team_name',
                    DB::raw('COUNT(DISTINCT c.id) as cases_count'),
                    DB::raw("COUNT(DISTINCT CASE WHEN c.status = 'pending' THEN c.id END) as pending_cases_count"),
                    DB::raw("COUNT(DISTINCT CASE WHEN c.status = 'active' THEN c.id END) as active_cases_count"),
                    DB::raw('COUNT(DISTINCT CASE WHEN d.id IS NOT NULL THEN c.id END) as resolved_cases_count')
                )
                ->groupBy('u.id', 'u.name', 'u.email', 'u.status', 'u.avatar_path', 't.name')
                ->orderByDesc('cases_count')
                ->orderBy('u.name')
                ->get();

            $memberCaseTypeCounts = DB::table('court_team_user as tu')
                ->join('court_staff as u', 'u.id', '=', 'tu.user_id')
                ->join('court_cases as c', function ($join) {
                    $join->on('c.assigned_member_user_id', '=', 'u.id')
                        ->orOn(function ($query) {
                            $query->on('c.assigned_user_id', '=', 'u.id')
                                ->whereNull('c.assigned_member_user_id');
                        });
                })
                ->leftJoin('court_case_types as ct', 'ct.id', '=', 'c.case_type_id')
                ->select(
                    'u.id as user_id',
                    DB::raw("COALESCE(ct.name, '__unknown__') as case_type"),
                    DB::raw('COUNT(DISTINCT c.id) as cases_count')
                )
                ->groupBy('u.id', 'case_type')
                ->get()
                ->groupBy('user_id');

            $memberCaseCounts = $memberCaseCounts->map(function ($member) use ($memberCaseTypeCounts) {
                $member->case_type_counts = $memberCaseTypeCounts
                    ->get($member->id, collect())
                    ->map(fn($row) => ['label' => $row->case_type, 'count' => (int) $row->cases_count])
                    ->values();
                return $member;
            });

            $genderCounts = DB::table('court_applicants')
                ->select('gender', DB::raw('COUNT(*) as total'))
                ->groupBy('gender')
                ->pluck('total', 'gender')
                ->toArray();

            $caseTypeCounts = DB::table('court_cases as c')
                ->leftJoin('court_case_types as t', 't.id', '=', 'c.case_type_id')
                ->select(DB::raw('COALESCE(t.name, "Unknown") as label'), DB::raw('COUNT(*) as total'))
                ->groupBy('label')
                ->orderByDesc('total')
                ->limit(8)
                ->get()
                ->mapWithKeys(fn($row) => [$row->label => $row->total])
                ->toArray();

            $keys = [];
            $values = [];
            for ($i = 5; $i >= 0; $i--) {
                $point = $reference->copy()->subMonths($i)->startOfMonth();
                $keys[] = $point->toDateString();
                $values[] = (int) ($months->firstWhere('ym', $point->format('Y-m'))->cnt ?? 0);
            }

            return compact(
                'totalCases', 'pendingCases', 'activeCases', 'resolvedCases', 'activeUsers',
                'recent', 'keys', 'values',
                'teamCaseCounts', 'memberCaseCounts', 'genderCounts', 'caseTypeCounts'
            );
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fromAllTime(array $data): array
    {
        return [
            'range' => 'all',
            'mode' => 'monthly',
            'kpis' => [
                'total' => (int) $data['totalCases'],
                'pending' => (int) $data['pendingCases'],
                'active' => (int) $data['activeCases'],
                'resolved' => (int) $data['resolvedCases'],
                'activeUsers' => (int) $data['activeUsers'],
            ],
            'recent' => $this->recentRows($data['recent']),
            'trend' => $this->series($data['keys'], $data['values']),
            'teams' => $this->teamRows($data['teamCaseCounts']),
            'members' => $this->memberRows($data['memberCaseCounts']),
            'gender' => $this->pairs(array_keys($data['genderCounts']), array_values($data['genderCounts'])),
            'caseTypes' => $this->pairs(array_keys($data['caseTypeCounts']), array_values($data['caseTypeCounts'])),
        ];
    }

    /**
     * @param  array<string, mixed>  $stats
     * @return array<string, mixed>
     */
    private function fromRange(string $range, array $stats): array
    {
        return [
            'range' => $range,
            'mode' => $stats['range']['mode'],
            'kpis' => [
                'total' => $stats['kpis']['totalCases'],
                'pending' => $stats['kpis']['pendingCases'],
                'active' => $stats['kpis']['activeCases'],
                'resolved' => $stats['kpis']['resolvedCases'],
                'activeUsers' => $stats['kpis']['activeUsers'],
            ],
            'recent' => $this->recentRows($stats['recent']),
            'trend' => $this->series($stats['charts']['line']['keys'], $stats['charts']['line']['values']),
            'teams' => $this->teamRows($stats['teamCaseCounts']),
            'members' => $this->memberRows($stats['memberCaseCounts']),
            'gender' => $this->pairs($stats['charts']['gender']['labels'], $stats['charts']['gender']['values']),
            'caseTypes' => $this->pairs($stats['charts']['types']['labels'], $stats['charts']['types']['values']),
        ];
    }

    /** @return list<array{id: int, case_number: string, title: ?string, status: string, created_at: ?string}> */
    private function recentRows(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row->id,
                'case_number' => (string) $row->case_number,
                'title' => $row->title,
                'status' => (string) $row->status,
                'created_at' => $row->created_at ? Carbon::parse($row->created_at)->toIso8601String() : null,
            ];
        }

        return $out;
    }

    /** @return list<array{id: int, name: string, cases: int}> */
    private function teamRows(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['id' => (int) $row->id, 'name' => (string) $row->name, 'cases' => (int) $row->cases_count];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function memberRows(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'team' => $row->team_name,
                'status' => (string) ($row->status ?? 'inactive'),
                'avatar_url' => filled($row->avatar_path) && Storage::disk('court_public')->exists($row->avatar_path)
                    ? Storage::disk('court_public')->url($row->avatar_path)
                    : null,
                'cases' => (int) $row->cases_count,
                'pending' => (int) ($row->pending_cases_count ?? 0),
                'active' => (int) ($row->active_cases_count ?? 0),
                'resolved' => (int) ($row->resolved_cases_count ?? 0),
                // '__unknown__' is a case without a type.
                'types' => collect($row->case_type_counts ?? [])
                    ->map(fn (array $type): array => ['label' => $type['label'] === '__unknown__' ? null : $type['label'], 'count' => (int) $type['count']])
                    ->values()->all(),
            ];
        }

        return $out;
    }

    /**
     * @param  list<string>  $keys
     * @param  list<int|string>  $values
     * @return list<array{key: string, value: int}>
     */
    private function series(array $keys, array $values): array
    {
        return array_map(fn (string $key, int|string $value): array => ['key' => $key, 'value' => (int) $value], $keys, $values);
    }

    /**
     * @param  list<int|string|null>  $labels
     * @param  list<int|string>  $values
     * @return list<array{key: string, value: int}>
     */
    private function pairs(array $labels, array $values): array
    {
        return array_map(fn ($label, $value): array => ['key' => (string) ($label ?? ''), 'value' => (int) $value], $labels, $values);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function rangeBounds(string $range): array
    {
        $now = Carbon::now();

        return match ($range) {
            '7d'  => [$now->copy()->subDays(6)->startOfDay(),         $now->copy()],
            '30d' => [$now->copy()->subDays(29)->startOfDay(),        $now->copy()],
            '90d' => [$now->copy()->subDays(89)->startOfDay(),        $now->copy()],
            '12m' => [$now->copy()->subMonths(11)->startOfMonth(),    $now->copy()],
            default=>[$now->copy()->subMonths(5)->startOfMonth(),     $now->copy()],
        };
    }

    /** @return array<string, mixed> */
    private function serviceHealth(): array
    {
        $database = true;
        try { DB::connection()->getPdo(); } catch (\Throwable) { $database = false; }
        $queue = null;
        try { $queue = Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : null; } catch (\Throwable) { $queue = null; }
        $settings = SystemSetting::current();

        return [
            'database' => $database,
            'queue_waiting' => $queue,
            'queue_ready' => $queue !== null,
            'mail' => (bool) ($settings->mail_enabled ?? false) && filled($settings->mail_host ?? config('mail.mailers.smtp.host')),
            'sms' => (bool) ($settings->sms_enabled ?? false),
            'sms_ready' => (bool) ($settings->sms_enabled ?? false) && filled($settings->sms_base_url ?? null),
            'telegram' => (bool) ($settings->telegram_enabled ?? false) && filled($settings->telegram_bot_token ?? null) && filled($settings->telegram_default_chat_id ?? null),
            'in_app' => Schema::hasTable('notifications'),
        ];
    }

    /**
     * Cached: the size query reads information_schema, which MySQL answers
     * slowly when its table statistics are cold.
     *
     * @return array<string, int|string|null>
     */
    private function dataStorage(): array
    {
        return Cache::remember('court.dashboard_storage_v1', self::CACHE_TTL, fn (): array => $this->measureStorage());
    }

    /** @return array<string, int|string|null> */
    private function measureStorage(): array
    {
        $tables = 0;
        try {
            // SHOW TABLES answers at once; Schema::getTables() also reads each table's statistics.
            $tables = DB::connection()->getDriverName() === 'mysql'
                ? count(DB::select('SHOW TABLES'))
                : count(Schema::getTables());
        } catch (\Throwable) {}
        $size = null;
        try {
            $driver = DB::connection()->getDriverName();
            $database = (string) DB::connection()->getDatabaseName();
            $bytes = match ($driver) {
                'mysql' => (int) DB::selectOne('select coalesce(sum(data_length + index_length), 0) as bytes from information_schema.tables where table_schema = ?', [$database])->bytes,
                'sqlite' => $this->sqliteSize($database),
                default => null,
            };
            $size = $bytes === null ? null : round($bytes / 1048576, 2).' MB';
        } catch (\Throwable) {}

        return [
            'tables' => $tables,
            'database_size' => $size,
            'storage_free' => disk_free_space(storage_path()),
            'audit_events' => Schema::hasTable('court_system_audits') ? (int) DB::table('court_system_audits')->count() : 0,
            'last_backup' => null,
        ];
    }

    private function sqliteSize(string $database): ?int
    {
        if ($database === ':memory:') return null;
        $path = realpath($database);
        if ($path === false || ! is_file($path)) return null;
        $bytes = filesize($path);
        return $bytes === false ? null : $bytes;
    }

    /**
     * Returns dashboard stats for a selected date range via AJAX.
     * Cached per range key so repeated toggles hit cache, not DB.
     */
    public function stats(Request $request): JsonResponse
    {
        // The same case statistics the dashboard shows only to cases.view holders.
        abort_unless((bool) $request->user()?->hasPermission('cases.view'), 403);

        if ($request->filled('start') && $request->filled('end')) {
            try {
                $start = Carbon::parse($request->input('start'))->startOfDay();
                $end   = Carbon::parse($request->input('end'))->endOfDay();
            } catch (\Exception) {
                return response()->json(['ok' => false, 'message' => 'Invalid date format.'], 422);
            }
        } else {
            [$start, $end] = $this->rangeBounds((string) $request->input('range', '6m'));
        }

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end->copy(), $start->copy()];
        }

        return response()->json($this->rangeStats($start, $end));
    }

    /** @return array<string, mixed> */
    private function rangeStats(Carbon $start, Carbon $end): array
    {
        // Cache key encodes the exact day range so custom ranges also benefit
        $cacheKey = 'dashboard_stats_v4_' . $start->toDateString() . '_' . $end->toDateString();

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($start, $end) {

            // All 4 KPI counts in a single pass using conditional aggregation
            $kpiRow = DB::table('court_cases as c')
                ->leftJoin('court_decisions as d', function ($join) {
                    $join->on('d.court_case_id', '=', 'c.id')
                        ->where('d.status', '=', 'published');
                })
                ->selectRaw("
                    COUNT(DISTINCT c.id) as total,
                    COUNT(DISTINCT CASE WHEN c.status = 'pending' THEN c.id END) as pending,
                    COUNT(DISTINCT CASE WHEN c.status = 'active' THEN c.id END) as active,
                    COUNT(DISTINCT CASE WHEN d.id IS NOT NULL THEN c.id END) as resolved
                ")
                ->whereBetween('c.created_at', [$start, $end])
                ->first();

            $totalCases    = (int) $kpiRow->total;
            $pendingCases  = (int) $kpiRow->pending;
            $resolvedCases = (int) $kpiRow->resolved;
            $activeCases   = (int) $kpiRow->active;

            $activeUsers = DB::table('court_staff')
                ->whereBetween('updated_at', [$start, $end])
                ->count();

            $recent = DB::table('court_cases')
                ->select('id', 'case_number', 'title', 'status', 'created_at')
                ->orderByDesc('created_at')
                ->limit(8)
                ->get();

            $teamCaseCounts = DB::table('court_teams as t')
                ->leftJoin('court_cases as c', function ($join) use ($start, $end) {
                    $join->on('c.assigned_team_id', '=', 't.id')
                        ->whereBetween('c.created_at', [$start, $end]);
                })
                ->select('t.id', 't.name', DB::raw('COUNT(c.id) as cases_count'))
                ->groupBy('t.id', 't.name')
                ->orderByDesc('cases_count')
                ->orderBy('t.name')
                ->get();

            $memberCaseCounts = DB::table('court_team_user as tu')
                ->join('court_staff as u', 'u.id', '=', 'tu.user_id')
                ->leftJoin('court_teams as t', 't.id', '=', 'tu.team_id')
                ->leftJoin('court_cases as c', function ($join) use ($start, $end) {
                    $join->whereBetween('c.created_at', [$start, $end])
                        ->where(function ($q) {
                            $q->whereColumn('c.assigned_member_user_id', 'u.id')
                                ->orWhere(function ($inner) {
                                    $inner->whereColumn('c.assigned_user_id', 'u.id')
                                        ->whereNull('c.assigned_member_user_id');
                                });
                        });
                })
                ->leftJoin('court_decisions as d', function ($join) {
                    $join->on('d.court_case_id', '=', 'c.id')
                        ->where('d.status', '=', 'published');
                })
                ->select(
                    'u.id', 'u.name', 'u.email', 'u.status', 'u.avatar_path',
                    't.name as team_name',
                    DB::raw('COUNT(DISTINCT c.id) as cases_count'),
                    DB::raw("COUNT(DISTINCT CASE WHEN c.status = 'pending' THEN c.id END) as pending_cases_count"),
                    DB::raw("COUNT(DISTINCT CASE WHEN c.status = 'active' THEN c.id END) as active_cases_count"),
                    DB::raw('COUNT(DISTINCT CASE WHEN d.id IS NOT NULL THEN c.id END) as resolved_cases_count')
                )
                ->groupBy('u.id', 'u.name', 'u.email', 'u.status', 'u.avatar_path', 't.name')
                ->orderByDesc('cases_count')
                ->orderBy('u.name')
                ->get();

            $memberCaseTypeCounts = DB::table('court_team_user as tu')
                ->join('court_staff as u', 'u.id', '=', 'tu.user_id')
                ->join('court_cases as c', function ($join) use ($start, $end) {
                    $join->whereBetween('c.created_at', [$start, $end])
                        ->where(function ($q) {
                            $q->whereColumn('c.assigned_member_user_id', 'u.id')
                                ->orWhere(function ($inner) {
                                    $inner->whereColumn('c.assigned_user_id', 'u.id')
                                        ->whereNull('c.assigned_member_user_id');
                                });
                        });
                })
                ->leftJoin('court_case_types as ct', 'ct.id', '=', 'c.case_type_id')
                ->select(
                    'u.id as user_id',
                    DB::raw("COALESCE(ct.name, '__unknown__') as case_type"),
                    DB::raw('COUNT(DISTINCT c.id) as cases_count')
                )
                ->groupBy('u.id', 'case_type')
                ->get()
                ->groupBy('user_id');

            $memberCaseCounts = $memberCaseCounts->map(function ($member) use ($memberCaseTypeCounts) {
                $member->case_type_counts = $memberCaseTypeCounts
                    ->get($member->id, collect())
                    ->map(fn($row) => ['label' => $row->case_type, 'count' => (int) $row->cases_count])
                    ->values();
                return $member;
            });

            $useDaily = $end->diffInDays($start) <= 45;
            $labels = [];
            $keys = [];
            $values = [];

            if ($useDaily) {
                $rows = DB::table('court_cases')
                    ->selectRaw("DATE(created_at) as day, COUNT(*) as cnt")
                    ->whereBetween('created_at', [$start, $end])
                    ->groupBy('day')
                    ->pluck('cnt', 'day');

                $cursor = $start->copy();
                while ($cursor->lte($end)) {
                    $labels[] = $cursor->format('M d');
                    $keys[] = $cursor->toDateString();
                    $values[] = (int) ($rows[$cursor->toDateString()] ?? 0);
                    $cursor->addDay();
                }
            } else {
                $rows = DB::table('court_cases')
                    ->selectRaw(\Modules\Court\Application\Support\Sql::yearMonth('created_at')." as ym, COUNT(*) as cnt")
                    ->whereBetween('created_at', [$start, $end])
                    ->groupBy('ym')
                    ->pluck('cnt', 'ym');

                $cursor = $start->copy()->startOfMonth();
                while ($cursor->lte($end)) {
                    $labels[] = $cursor->format('M Y');
                    $keys[] = $cursor->toDateString();
                    $values[] = (int) ($rows[$cursor->format('Y-m')] ?? 0);
                    $cursor->addMonth();
                }
            }

            // Both gender + case-type in one query pass each
            $genderCounts  = DB::table('court_applicants')
                ->select('gender', DB::raw('COUNT(*) as total'))
                ->whereBetween('created_at', [$start, $end])
                ->groupBy('gender')
                ->pluck('total', 'gender')
                ->toArray();

            $caseTypeCounts = DB::table('court_cases as c')
                ->leftJoin('court_case_types as t', 't.id', '=', 'c.case_type_id')
                ->select(DB::raw('COALESCE(t.name, "Unknown") as label'), DB::raw('COUNT(*) as total'))
                ->whereBetween('c.created_at', [$start, $end])
                ->groupBy('label')
                ->orderByDesc('total')
                ->get()
                ->map(fn($row) => ['label' => $row->label, 'total' => (int) $row->total]);

            return [
                'ok'    => true,
                'range' => [
                    'start' => $start->toDateString(),
                    'end'   => $end->toDateString(),
                    'mode'  => $useDaily ? 'daily' : 'monthly',
                ],
                'kpis' => [
                    'totalCases'    => $totalCases,
                    'pendingCases'  => $pendingCases,
                    'resolvedCases' => $resolvedCases,
                    'activeCases'   => $activeCases,
                    'activeUsers'   => $activeUsers,
                ],
                'recent'          => $recent,
                'teamCaseCounts'  => $teamCaseCounts,
                'memberCaseCounts'=> $memberCaseCounts,
                'charts' => [
                    'line'   => ['labels' => $labels, 'keys' => $keys, 'values' => $values],
                    'gender' => [
                        'labels' => array_keys($genderCounts),
                        'values' => array_values($genderCounts),
                    ],
                    'types'  => [
                        'labels' => $caseTypeCounts->pluck('label')->all(),
                        'values' => $caseTypeCounts->pluck('total')->all(),
                    ],
                ],
            ];
        });
    }
}
