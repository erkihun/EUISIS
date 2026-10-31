<?php

declare(strict_types=1);

namespace Modules\Court\Infrastructure\Console;

use Modules\Court\Domain\Models\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Read-only report of the roles whose day-to-day access changes with the
 * Inertia/React release, which moved several permission checks from "hide the
 * button" to "enforced on the server". Run it on production before go-live
 * and grant what the report lists where the old behaviour was intended.
 *
 * Permissions are evaluated per role because staff work under one active role
 * at a time (see User::hasPermission()). The admin role grants everything and
 * is never reported.
 */
class AccessUpgradeReport extends Command
{
    protected $signature = 'court:access-report {--json : Print the findings as JSON}';

    protected $description = 'List roles affected by the permission checks added in the React release (read-only)';

    /**
     * Each check: roles that hold `holds` (or every role when null) but lack
     * one of `needs`. `visible` says whether staff will notice a difference
     * in the interface, or only requests the old UI never offered are refused.
     *
     * @var list<array{key: string, title: string, holds: ?string, needs: list<string>, visible: bool, effect: string}>
     */
    private const CHECKS = [
        [
            'key' => 'dashboard_cases',
            'title' => 'Dashboard case statistics',
            'holds' => null,
            'needs' => ['cases.view'],
            'visible' => true,
            'effect' => 'The dashboard shows no case figures, workload or charts for this role.',
        ],
        [
            'key' => 'dashboard_system',
            'title' => 'Dashboard service health and storage',
            'holds' => null,
            'needs' => ['settings.manage'],
            'visible' => true,
            'effect' => 'The service health and data storage panels are hidden for this role.',
        ],
        [
            'key' => 'landing_manager',
            'title' => 'Landing page manager',
            'holds' => null,
            'needs' => ['settings.manage'],
            'visible' => true,
            'effect' => 'The Landing Page item leaves the Settings menu and its pages answer 403 for this role.',
        ],
        [
            'key' => 'case_actions',
            'title' => 'Hearings, files and messages on the case page',
            'holds' => 'cases.edit',
            'needs' => ['hearing.create', 'hearing.update', 'hearing.delete', 'file.create', 'file.delete', 'message.create'],
            'visible' => false,
            'effect' => 'The case page already hid these actions; direct requests are now refused too.',
        ],
        [
            'key' => 'bench_notes',
            'title' => 'Bench note actions',
            'holds' => 'bench-notes.manage',
            'needs' => ['bench-notes.view', 'bench-notes.create', 'bench-notes.update', 'bench-notes.delete'],
            'visible' => false,
            'effect' => 'The old pages already hid these actions; direct requests are now refused too.',
        ],
        [
            'key' => 'user_self_edit',
            'title' => 'Editing roles, status or password of their own account',
            'holds' => 'court.users.update',
            'needs' => ['users.manage'],
            'visible' => true,
            'effect' => 'Can still edit their own profile details, but no longer their roles, status, password, signature or stamp.',
        ],
    ];

    public function handle(): int
    {
        $roles = Role::query()
            ->with('permissions:id,name')
            ->withCount(['users as active_users_count' => fn ($query) => $query->where('status', 'active')])
            ->orderBy('name')
            ->get()
            ->reject(fn (Role $role): bool => mb_strtolower($role->name) === 'admin');

        $findings = collect(self::CHECKS)->map(fn (array $check): array => [
            ...$check,
            'roles' => $this->affectedRoles($roles, $check),
        ]);

        if ($this->option('json')) {
            $this->line((string) json_encode($findings->values(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Access changes in the React release, by role (admin excluded; read-only).');
        $this->newLine();

        foreach ($findings as $finding) {
            $this->line(sprintf('<comment>%s</comment> %s', $finding['title'], $finding['visible'] ? '[visible change]' : '[no visible change]'));
            $this->line('  '.$finding['effect']);

            if ($finding['roles'] === []) {
                $this->line('  No roles affected.');
                $this->newLine();

                continue;
            }

            $this->table(
                ['Role', 'Active users', 'Missing permissions'],
                array_map(fn (array $row): array => [$row['role'], $row['activeUsers'], implode(', ', $row['missing'])], $finding['roles']),
            );
        }

        $this->line('To keep the old behaviour for a role, grant the missing permissions under Users & roles → Roles.');
        $this->line('Also changed for everyone, by design: bench notes can only be edited by their author or an administrator;');
        $this->line('case records of cases not yet accepted open only for reviewers and the assigned officer.');

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Role>  $roles
     * @param  array{holds: ?string, needs: list<string>}  $check
     * @return list<array{role: string, activeUsers: int, missing: list<string>}>
     */
    private function affectedRoles(Collection $roles, array $check): array
    {
        return $roles
            ->map(function (Role $role) use ($check): ?array {
                $granted = $role->permissions->pluck('name');
                if ($check['holds'] !== null && ! $granted->contains($check['holds'])) {
                    return null;
                }

                $missing = array_values(array_diff($check['needs'], $granted->all()));

                return $missing === [] ? null : [
                    'role' => (string) $role->name,
                    'activeUsers' => (int) $role->active_users_count,
                    'missing' => $missing,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
