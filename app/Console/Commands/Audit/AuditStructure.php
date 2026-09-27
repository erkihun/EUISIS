<?php

declare(strict_types=1);

namespace App\Console\Commands\Audit;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Organization → unit → position → assignment consistency. Read-only.
 *
 * positions.organization_id carries no foreign key, and a unit's parent or a
 * position's unit may sit in another organization if data was imported or
 * edited outside the application, so these are checked here.
 */
class AuditStructure extends ReadOnlyAuditCommand
{
    protected $signature = 'structure:audit {--json : Also print a machine-readable summary} {--strict : Exit 1 on any finding, not only HIGH}';

    protected $description = 'Read-only: report broken organization, unit, position and assignment structure';

    protected function checks(): array
    {
        return [
            [
                'key' => 'organization_units.cycle', 'severity' => 'HIGH',
                'label' => 'Organization units in a parent cycle',
                'why' => 'A unit that is its own ancestor makes subtree scope and hierarchy views loop.',
                'rows' => fn () => $this->unitsInCycles(),
            ],
            [
                'key' => 'organization_units.parent_organization', 'severity' => 'HIGH',
                'label' => 'Units whose parent unit belongs to another organization',
                'why' => 'Subtree scope follows parent links; a cross-organization parent leaks one organization\'s staff into another\'s scope.',
                'rows' => fn () => DB::table('organization_units as u')->join('organization_units as p', 'p.id', '=', 'u.parent_unit_id')
                    ->whereColumn('p.organization_id', '!=', 'u.organization_id')->whereNull('u.deleted_at')->pluck('u.id'),
            ],
            [
                'key' => 'positions.organization', 'severity' => 'HIGH',
                'label' => 'Positions whose organization does not exist',
                'why' => 'positions.organization_id has no foreign key; an orphan position is outside every scope.',
                'rows' => fn () => DB::table('positions as p')->leftJoin('organizations as o', 'o.id', '=', 'p.organization_id')
                    ->whereNotNull('p.organization_id')->whereNull('o.id')->whereNull('p.deleted_at')->pluck('p.id'),
            ],
            [
                'key' => 'positions.unit_organization', 'severity' => 'HIGH',
                'label' => 'Positions whose unit belongs to another organization',
                'why' => 'A position and its unit must agree on the organization.',
                'rows' => fn () => DB::table('positions as p')->join('organization_units as u', 'u.id', '=', 'p.organization_unit_id')
                    ->whereColumn('u.organization_id', '!=', 'p.organization_id')->whereNull('p.deleted_at')->pluck('p.id'),
            ],
            [
                'key' => 'employee_assignments.position_organization', 'severity' => 'HIGH',
                'label' => 'Current assignments whose position belongs to another organization',
                'why' => 'Scope, card header and cafeteria policy follow the assignment organization; the position says otherwise.',
                'rows' => fn () => DB::table('employee_assignments as a')->join('positions as p', 'p.id', '=', 'a.position_id')
                    ->where('a.is_current', true)->whereColumn('p.organization_id', '!=', 'a.organization_id')->pluck('a.id'),
            ],
            [
                'key' => 'employee_assignments.inactive_organization', 'severity' => 'MEDIUM',
                'label' => 'Current assignments in an organization that is not active',
                'why' => 'Staff of a merged, dissolved, archived or deleted organization still resolve to it.',
                'rows' => fn () => DB::table('employee_assignments as a')->join('organizations as o', 'o.id', '=', 'a.organization_id')
                    ->where('a.is_current', true)->where('a.assignment_status', 'active')
                    ->where(fn ($q) => $q->where('o.status', '!=', 'active')->orWhereNotNull('o.deleted_at'))->pluck('a.id'),
            ],
            [
                'key' => 'employees.active_without_assignment', 'severity' => 'MEDIUM',
                'label' => 'Active employees without a current assignment',
                'why' => 'They cannot be scoped, carded or served by a cafeteria policy.',
                'rows' => fn () => DB::table('employees as e')->where('e.status', 'active')
                    ->whereNotExists(fn ($q) => $q->from('employee_assignments as a')->whereColumn('a.employee_id', 'e.id')->where('a.is_current', true))
                    ->pluck('e.id'),
            ],
        ];
    }

    /** @return Collection<int, string> units that reach themselves by following parent links */
    private function unitsInCycles(): Collection
    {
        $parents = DB::table('organization_units')->whereNull('deleted_at')->pluck('parent_unit_id', 'id');
        $inCycle = [];
        foreach ($parents->keys() as $start) {
            $seen = [];
            for ($node = $start; $node !== null && ! isset($seen[$node]); $node = $parents[$node] ?? null) {
                $seen[$node] = true;
            }
            if ($node === $start) {
                $inCycle[] = $start;
            }
        }

        return collect($inCycle);
    }
}
