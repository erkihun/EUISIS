<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Euisis\EuisisClient;
use App\Services\Euisis\EuisisMirror;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Mirrors the EUISIS organizations, units and employees (with their current
 * placement) over the EUISIS API. The first run takes everything; later runs
 * ask only for records changed since the previous run (`updated_after`).
 * ID cards are mirrored at scan time (EuisisCredentialLookup).
 */
class SyncFromEuisis extends Command
{
    protected $signature = 'cafeteria:sync-from-euisis {--full : Take every record again, not only the changes} {--organizations-only : Skip the employees}';

    protected $description = 'Mirror the EUISIS organizations and employees the cafeteria serves, over the EUISIS API';

    public function handle(EuisisClient $euisis, EuisisMirror $mirror): int
    {
        if (! $euisis->configured()) {
            $this->error('EUISIS is not configured: set EUISIS_URL and EUISIS_TOKEN.');

            return self::FAILURE;
        }
        $startedAt = now();
        $since = $this->option('full') ? null : $this->state('employees_updated_after');

        try {
            $organizations = $this->pages(fn (int $page) => $euisis->organizations($page, $this->option('full') ? null : $this->state('organizations_updated_after')), function (array $row) use ($mirror, $euisis): void {
                $organization = $mirror->organization($row);
                $this->pages(fn (int $page) => $euisis->organizationUnits($organization->id, $page), fn (array $unit) => $mirror->unit($organization->id, $unit));
            });
            $this->setState('organizations_updated_after', $startedAt);
            $this->info("Organizations: {$organizations} mirrored.");

            if (! $this->option('organizations-only')) {
                $employees = $this->pages(fn (int $page) => $euisis->employees($page, $since), fn (array $row) => $mirror->employee($row));
                $this->setState('employees_updated_after', $startedAt);
                $this->info("Employees: {$employees} mirrored".($since !== null ? " (changed since {$since})" : '').'.');
            }
        } catch (Throwable $e) {
            $this->error('Sync stopped: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->setState('last_run_at', $startedAt);

        return self::SUCCESS;
    }

    /** @param  callable(int): array{data: list<array<string, mixed>>, meta: array<string, int>}  $fetch */
    private function pages(callable $fetch, callable $each): int
    {
        $count = 0;
        for ($page = 1; ; $page++) {
            $result = $fetch($page);
            foreach ($result['data'] as $row) {
                $each($row);
                $count++;
            }
            if ($page >= (int) ($result['meta']['last_page'] ?? $page) || $result['data'] === []) {
                break;
            }
        }

        return $count;
    }

    private function state(string $key): ?string
    {
        $value = DB::table('euisis_sync_state')->where('key', $key)->value('value');

        return $value === null ? null : (string) $value;
    }

    private function setState(string $key, Carbon $value): void
    {
        DB::table('euisis_sync_state')->updateOrInsert(['key' => $key], ['value' => $value->toIso8601String(), 'updated_at' => now()]);
    }
}
