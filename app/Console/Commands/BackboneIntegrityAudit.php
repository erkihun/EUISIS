<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AssignmentStatus;
use App\Services\Employees\EmploymentStatusTransition;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only checks of the master backbone chain
 * Organization -> unit -> position -> employee -> assignment -> card.
 * Nothing is changed; each finding names how to resolve it.
 */
class BackboneIntegrityAudit extends Command
{
    protected $signature = 'backbone:audit-integrity {--strict : Exit with a failure code when any finding is reported}';

    protected $description = 'Report broken links in the organization / position / employee / assignment / card chain (read-only)';

    public function handle(): int
    {
        $checks = [
            'Positions naming an organization that does not exist' => DB::table('positions')
                ->whereNotNull('organization_id')
                ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('organizations')->whereColumn('organizations.id', 'positions.organization_id')),
            'Positions whose unit belongs to another organization' => DB::table('positions')
                ->join('organization_units', 'organization_units.id', '=', 'positions.organization_unit_id')
                ->whereNotNull('positions.organization_id')
                ->whereColumn('organization_units.organization_id', '!=', 'positions.organization_id'),
            'Employees pointing at a missing, closed or foreign assignment' => DB::table('employees')
                ->whereNotNull('employees.current_assignment_id')
                ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('employee_assignments')
                    ->whereColumn('employee_assignments.id', 'employees.current_assignment_id')
                    ->whereColumn('employee_assignments.employee_id', 'employees.id')
                    ->where('employee_assignments.is_current', true)),
            'Current assignments the employee does not point at' => DB::table('employee_assignments')
                ->join('employees', 'employees.id', '=', 'employee_assignments.employee_id')
                ->where('employee_assignments.is_current', true)
                ->where(fn (Builder $q) => $q->whereNull('employees.current_assignment_id')
                    ->orWhereColumn('employees.current_assignment_id', '!=', 'employee_assignments.id')),
            'Card replacement records not linked to the new card' => DB::table('card_replacements')
                ->whereColumn('new_card_id', 'old_card_id')
                ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('id_cards')->whereColumn('id_cards.previous_card_id', 'card_replacements.old_card_id')),
            // Separations recorded before ADR 0002 left the placement open and the seat taken.
            'Separated employees whose placement is still open' => DB::table('employees')
                ->whereIn('employees.status', array_map(fn ($status): string => $status->value, EmploymentStatusTransition::SEPARATED))
                ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('employee_assignments')
                    ->whereColumn('employee_assignments.id', 'employees.current_assignment_id')
                    ->where('employee_assignments.assignment_status', AssignmentStatus::Active->value)),
        ];

        $total = 0;
        $rows = [];
        foreach ($checks as $label => $query) {
            $count = (int) $query->count();
            $total += $count;
            $rows[] = [$label, number_format($count)];
        }
        $rows[] = ['positions.organization_id foreign key present', $this->hasPositionOrganizationKey() ? 'yes' : 'no'];
        $this->table(['Check', 'Result'], $rows);

        if ($total === 0) {
            $this->info('No broken links found.');
        } else {
            $this->warn('Findings are listed above. Repair them through the backbone screens or a reviewed data fix; card links: cards:audit-replacement-links --repair.');
        }

        return $total > 0 && $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }

    private function hasPositionOrganizationKey(): bool
    {
        return collect(Schema::getForeignKeys('positions'))->contains(fn (array $key): bool => $key['columns'] === ['organization_id']);
    }
}
