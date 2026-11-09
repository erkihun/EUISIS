<?php

declare(strict_types=1);

namespace App\Console\Commands\Backbone;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 7 evidence: whether the two duplicate masters can be retired
 * (docs/architecture/backbone-refactor-migration.md, section 9).
 *
 *  - institution_offices: the legacy organization master. It can go once every
 *    live office has its organization unit.
 *  - users.national_id: a copy of the employee's identifier on the account. It
 *    can go once every account that holds one is linked to an employee holding
 *    the same identifier.
 *
 * Read-only. Prints counts only: no identifier, name or national ID ever
 * leaves the database. Nothing is retired here; retirement is a separate,
 * reviewed change made after this report comes back clean on live data.
 */
class DuplicateMastersReport extends Command
{
    protected $signature = 'backbone:duplicate-masters {--json : Print the report as JSON (for the change record)}';

    protected $description = 'Report whether institution_offices and users.national_id can be retired (counts only, read-only)';

    public function handle(): int
    {
        $report = ['institution_offices' => $this->offices(), 'users_national_id' => $this->accountIdentifiers()];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        foreach ($report as $section => $figures) {
            $this->newLine();
            $this->info($section.': '.($figures['ready_to_retire'] ? 'ready to retire after review' : 'not ready'));
            $this->table(['Figure', 'Count'], collect($figures)->except(['ready_to_retire', 'next_step'])->map(fn ($value, $key): array => [$key, $value])->values()->all());
            $this->line('Next step: '.$figures['next_step']);
        }

        return self::SUCCESS;
    }

    /** @return array<string, int|bool|string> */
    private function offices(): array
    {
        if (! Schema::hasTable('institution_offices')) {
            return ['present' => false, 'ready_to_retire' => true, 'next_step' => 'Nothing to retire: the table does not exist.'];
        }

        $live = fn (): Builder => DB::table('institution_offices')->whereNull('institution_offices.deleted_at');
        $mapped = $live()->whereExists(fn (Builder $query) => $query->selectRaw('1')->from('organization_units')
            ->whereColumn('organization_units.institution_office_id', 'institution_offices.id'))->count();
        $active = $live()->count();
        $unmapped = $active - $mapped;

        return [
            'present' => true,
            'offices_total' => DB::table('institution_offices')->count(),
            'offices_live' => $active,
            'offices_with_unit' => $mapped,
            'offices_without_unit' => $unmapped,
            'units_pointing_to_an_office' => DB::table('organization_units')->whereNotNull('institution_office_id')->count(),
            'office_relationships' => Schema::hasTable('institution_office_relationships')
                ? DB::table('institution_office_relationships')->whereNull('deleted_at')->count() : 0,
            'ready_to_retire' => $unmapped === 0,
            'next_step' => $unmapped === 0
                ? 'Every live office has its organization unit. Retire the table in a reviewed migration that keeps a backup copy.'
                : 'Run institution-offices:migrate-to-organization-units (dry run first), then report again.',
        ];
    }

    /** @return array<string, int|bool|string> */
    private function accountIdentifiers(): array
    {
        $withIdentifier = fn (): Builder => DB::table('users')->whereNotNull('users.national_id_hash');
        $linked = fn (): Builder => $withIdentifier()->whereNotNull('users.employee_id')
            ->join('employees', 'employees.id', '=', 'users.employee_id');

        $accounts = $withIdentifier()->count();
        $accountOnly = $withIdentifier()->whereNull('users.employee_id')->count();
        $matching = $linked()->whereColumn('employees.national_id_hash', 'users.national_id_hash')->count();
        $different = $linked()->whereNotNull('employees.national_id_hash')->whereColumn('employees.national_id_hash', '!=', 'users.national_id_hash')->count();
        $employeeWithout = $linked()->whereNull('employees.national_id_hash')->count();
        $ready = $accountOnly === 0 && $different === 0 && $employeeWithout === 0;

        return [
            'accounts_with_national_id' => $accounts,
            'linked_and_matching' => $matching,
            'linked_but_different' => $different,
            'linked_employee_has_none' => $employeeWithout,
            'account_not_linked' => $accountOnly,
            'ready_to_retire' => $ready,
            'next_step' => $ready
                ? 'Every account copy equals its employee\'s identifier. Review sign-in and recovery, then read from the employee and retire the copy.'
                : 'Resolve differing and unlinked accounts with HR first; retiring now would lose the only copy for those accounts.',
        ];
    }
}
