<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * The pilot indicators for one period, so "before the pilot" and "after"
 * are compared on numbers rather than impressions (docs/pilot-measurement.md).
 */
class PilotBaseline extends Command
{
    protected $signature = 'pilot:baseline
        {--from= : First day of the window (Y-m-d, inclusive); default 30 days before --to}
        {--to= : Last day of the window (Y-m-d, inclusive); default today}
        {--organization= : Limit the indicators to one organization id}
        {--json : Print the snapshot as JSON instead of a table}
        {--save : Also keep the snapshot under storage/app/private/pilot/}';

    protected $description = 'Measure the pilot indicators (registrations, grievances, public feedback, ID cards, MFA failures) for a period';

    public function handle(): int
    {
        try {
            [$from, $to] = $this->window();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $organization = $this->option('organization') ? (string) $this->option('organization') : null;
        $snapshot = $this->snapshot($from, $to, $organization);

        if ($this->option('json')) {
            $this->line(json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info(sprintf('Pilot indicators %s → %s%s', $from->toDateString(), $to->toDateString(), $organization ? ' · organization '.$organization : ''));
            $this->table(['Indicator', 'Value'], array_map(
                static fn (string $key, mixed $value): array => [$key, $value === null ? '—' : (string) $value],
                array_keys($snapshot['indicators']),
                $snapshot['indicators'],
            ));
        }

        if ($this->option('save')) {
            $path = 'pilot/baseline-'.$to->toDateString().($organization ? '-'.$organization : '').'.json';
            Storage::disk('local')->put($path, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->comment('Saved to storage/app/private/'.$path);
        }

        return self::SUCCESS;
    }

    /**
     * @return array{window: array{from: string, to: string}, organization_id: ?string, generated_at: string, indicators: array<string, int|float|null>}
     */
    public function snapshot(CarbonImmutable $from, CarbonImmutable $to, ?string $organization): array
    {
        $start = $from->startOfDay();
        $end = $to->endOfDay();

        $employees = $this->live('employees')->whereBetween('created_at', [$start, $end]);
        $grievancesSubmitted = $this->live('grievances')->whereBetween('submitted_at', [$start, $end]);
        $grievancesResolved = $this->live('grievances')->whereBetween('resolved_at', [$start, $end]);
        $grievancesStale = $this->live('grievances')
            ->whereNotNull('submitted_at')->where('submitted_at', '<', $end->subDays(30))
            ->whereNull('resolved_at')->whereNull('closed_at');
        $feedback = DB::table('employee_service_feedback')->whereBetween('submitted_at', [$start, $end]);
        $cards = DB::table('id_cards')->whereBetween('issued_at', [$start, $end]);
        $mfaFailures = DB::table('audit_logs')->where('event_type', 'mfa.challenge_failed')->whereBetween('created_at', [$start, $end]);

        if ($organization !== null) {
            foreach ([$grievancesSubmitted, $grievancesResolved, $grievancesStale, $feedback, $mfaFailures] as $query) {
                $query->where('organization_id', $organization);
            }
            // An employee belongs to an organization through the current assignment.
            $members = DB::table('employee_assignments')->where('organization_id', $organization)->select('employee_id');
            $employees->whereIn('id', $members);
            $cards->whereIn('employee_id', $members);
        }

        $durations = $grievancesResolved->clone()->whereNotNull('submitted_at')->get(['submitted_at', 'resolved_at'])
            ->map(fn (object $row): float => round(CarbonImmutable::parse($row->submitted_at)->diffInSeconds(CarbonImmutable::parse($row->resolved_at)) / 86400, 1))
            ->sort()->values();

        $rating = $feedback->clone()->whereNotNull('rating')->avg('rating');

        return [
            'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'organization_id' => $organization,
            'generated_at' => now()->toIso8601String(),
            'indicators' => [
                'employees_registered' => $this->count($employees),
                'grievances_submitted' => $this->count($grievancesSubmitted),
                'grievances_resolved' => $this->count($grievancesResolved),
                'grievance_median_resolution_days' => $durations->isEmpty() ? null : self::median($durations->all()),
                'grievances_open_over_30_days' => $this->count($grievancesStale),
                'public_feedback_submitted' => $this->count($feedback),
                'public_feedback_average_rating' => $rating === null ? null : round((float) $rating, 2),
                'id_cards_issued' => $this->count($cards),
                'mfa_challenge_failures' => $this->count($mfaFailures),
            ],
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(): array
    {
        $to = $this->option('to') ? self::day((string) $this->option('to'), '--to') : CarbonImmutable::today();
        $from = $this->option('from') ? self::day((string) $this->option('from'), '--from') : $to->subDays(30);
        if ($from->gt($to)) {
            throw new InvalidArgumentException('--from must not be after --to.');
        }

        return [$from, $to];
    }

    private static function day(string $value, string $option): CarbonImmutable
    {
        $day = CarbonImmutable::createFromFormat('Y-m-d', $value);
        if ($day === false || $day->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($option.' must be a date in Y-m-d form.');
        }

        return $day;
    }

    private function count(Builder $query): int
    {
        return (int) $query->count();
    }

    /** Rows in the recycle bin (soft-deleted) are not counted. */
    private function live(string $table): Builder
    {
        $query = DB::table($table);

        return Schema::hasColumn($table, 'deleted_at') ? $query->whereNull('deleted_at') : $query;
    }

    /**
     * @param  list<float>  $sorted
     */
    private static function median(array $sorted): float
    {
        $n = count($sorted);
        $middle = intdiv($n, 2);

        return $n % 2 === 1 ? $sorted[$middle] : round(($sorted[$middle - 1] + $sorted[$middle]) / 2, 1);
    }
}
