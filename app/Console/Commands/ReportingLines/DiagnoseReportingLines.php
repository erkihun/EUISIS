<?php

declare(strict_types=1);

namespace App\Console\Commands\ReportingLines;

use App\Models\Organization;
use App\Services\ReportingLines\ReportingDiagnostics;
use App\Support\Database\HeadPositionIndexes;
use Illuminate\Console\Command;

/**
 * Read-only integrity report of the reporting lines (ADR 0025). Changes
 * nothing. `--preflight` adds what a deployment needs to know first: whether
 * the one-head indexes are installed and which duplicates block them.
 */
class DiagnoseReportingLines extends Command
{
    protected $signature = 'reporting:diagnose
        {--organization=* : Organization code or id (repeatable); every active organization when omitted}
        {--as-of= : Resolve on this date (Y-m-d) instead of today}
        {--preflight : Also report the one-head database indexes and the duplicates that block them}
        {--json : Print the full report as JSON}';

    protected $description = 'Report missing, vacant, doubled or looping reporting lines without changing anything (ADR 0025)';

    public function __construct(private readonly ReportingDiagnostics $diagnostics)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $asOf = $this->option('as-of') ?: null;
        $filters = array_filter((array) $this->option('organization'));
        $organizations = Organization::query()
            ->when($filters !== [], fn ($q) => $q->where(fn ($w) => $w->whereIn('code', $filters)->orWhereIn('id', $filters)))
            ->when($filters === [], fn ($q) => $q->where('status', 'active'))
            ->orderBy('name_en')
            ->get();

        if ($organizations->isEmpty()) {
            $this->warn(__('reporting-lines.commands.no_organizations'));

            return self::FAILURE;
        }

        $report = ['as_of' => $asOf, 'organizations' => [], 'preflight' => null];
        $worst = 0;
        foreach ($organizations as $organization) {
            $result = $this->diagnostics->forOrganization($organization, $asOf);
            $report['organizations'][] = $result;
            $worst = max($worst, $result['summary']['errors'] > 0 ? 2 : ($result['summary']['warnings'] > 0 ? 1 : 0));
        }
        if ($this->option('preflight')) {
            $report['preflight'] = ['head_indexes' => HeadPositionIndexes::status(), 'violations' => HeadPositionIndexes::violations()];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $worst === 2 ? self::FAILURE : self::SUCCESS;
        }

        foreach ($report['organizations'] as $result) {
            $s = $result['summary'];
            $this->info(sprintf('%s (%s) — units %d, with head %d, positions %d, errors %d, warnings %d',
                $result['organization']['name_en'], $result['as_of'], $s['units'], $s['units_with_head'], $s['positions'], $s['errors'], $s['warnings']));
            $rows = array_map(fn (array $f): array => [
                $f['severity'], $f['title'], $f['unit_name_en'] ?? '', $f['position_title_en'] ?? '', $f['count'] ?? '', $f['detail'],
            ], $result['findings']);
            if ($rows !== []) {
                $this->table(['Severity', 'Finding', 'Unit', 'Position', 'Count', 'Detail'], $rows);
            }
        }

        if ($report['preflight'] !== null) {
            $this->newLine();
            $this->info(__('reporting-lines.commands.preflight_title'));
            foreach ($report['preflight']['head_indexes'] as $name => $present) {
                $line = sprintf('  %s: %s', $name, __($present ? 'reporting-lines.commands.index_present' : 'reporting-lines.commands.index_missing'));
                if (isset($report['preflight']['violations'][$name])) {
                    $line .= sprintf(' (%d %s)', $report['preflight']['violations'][$name], __('reporting-lines.commands.violations'));
                }
                $this->line($line);
            }
        }

        return $worst === 2 ? self::FAILURE : self::SUCCESS;
    }
}
