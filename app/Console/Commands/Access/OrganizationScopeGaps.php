<?php

declare(strict_types=1);

namespace App\Console\Commands\Access;

use App\Services\OrganizationScope\ScopeGapReport;
use Illuminate\Console\Command;

/**
 * Lists the accounts ADR 0003 confines or leaves unscoped (ScopeGapReport).
 * Read-only; prints account ids, names and roles, never e-mail, phone or
 * national ID.
 */
class OrganizationScopeGaps extends Command
{
    protected $signature = 'access:scope-gaps {--json : Print the report as JSON}';

    protected $description = 'Read-only: list accounts without an organization scope that hold an organization-level role or direct permissions';

    public function handle(ScopeGapReport $report): int
    {
        $rows = $report->rows();

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->info('Every account with an organization-level role or direct permissions has an explicit organization scope.');

            return self::SUCCESS;
        }

        $this->table(['User', 'Name', 'Roles', 'Finding'], array_map(fn (array $row): array => [$row['id'], $row['name'], implode(', ', $row['roles']) ?: '—', $row['finding']], $rows));
        $this->line(count($rows).' account(s) need an explicit organization scope (Users › Organization scopes), or a global role if their work is city-wide.');

        return self::SUCCESS;
    }
}
