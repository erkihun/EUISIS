<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Rbac\DefaultRoleMatrix;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\PermissionUsageScanner;
use Illuminate\Console\Command;

/**
 * Read-only RBAC health report (docs/rbac-audit-report.md). Changes nothing:
 * stale permissions and legacy roles are listed for a separate, reviewed
 * clean-up, never deleted automatically.
 */
class RbacReport extends Command
{
    protected $signature = 'rbac:report {--json : Print the report as JSON} {--strict : Exit non-zero when code references unseeded permissions or system roles drift}';

    protected $description = 'Compare code, the permission catalog, the default role matrix and the database';

    public function handle(PermissionUsageScanner $scanner): int
    {
        $catalog = PermissionCatalog::names();
        $dbPermissions = Permission::query()->where('guard_name', PermissionCatalog::GUARD)->pluck('name')->all();
        $matrix = DefaultRoleMatrix::roles();
        $dbRoles = Role::query()->where('guard_name', PermissionCatalog::GUARD)->with('permissions:id,name')->get()->keyBy('name');

        $drift = [];
        foreach ($matrix as $name => $definition) {
            $role = $dbRoles->get($name);
            if ($role === null) {
                continue;
            }
            $current = $role->permissions->pluck('name')->all();
            $missing = array_values(array_diff($definition['permissions'], $current));
            $extra = array_values(array_diff($current, $definition['permissions']));
            if ($missing !== [] || $extra !== []) {
                $drift[$name] = ['missing' => $missing, 'extra' => $extra];
            }
        }

        $report = [
            'catalog_permissions' => count($catalog),
            'database_permissions' => count($dbPermissions),
            'referenced_but_not_in_catalog' => $scanner->missingFromCatalog(),
            'stale_candidates_never_referenced' => $scanner->unusedCatalogEntries(),
            'dynamic_prefixes' => PermissionUsageScanner::DYNAMIC_PREFIXES,
            'database_permissions_not_in_catalog' => array_values(array_diff($dbPermissions, $catalog)),
            'catalog_permissions_not_in_database' => array_values(array_diff($catalog, $dbPermissions)),
            'system_roles_missing_in_database' => array_values(array_diff(array_keys($matrix), $dbRoles->keys()->all())),
            'custom_or_legacy_roles' => array_values(array_diff($dbRoles->keys()->all(), array_keys($matrix))),
            'system_role_drift' => $drift,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info("Catalog: {$report['catalog_permissions']} permissions · database: {$report['database_permissions']}");
            foreach ($report as $key => $value) {
                if (! is_array($value) || $key === 'dynamic_prefixes') {
                    continue;
                }
                $this->newLine();
                $this->line(str_replace('_', ' ', ucfirst($key)).': '.count($value));
                foreach ($value as $name => $entry) {
                    $this->line('  - '.(is_array($entry) ? "{$name}: +".count($entry['missing']).' missing, '.count($entry['extra']).' extra' : $entry));
                }
            }
        }

        $failed = $report['referenced_but_not_in_catalog'] !== [] || $drift !== [];

        return $this->option('strict') && $failed ? self::FAILURE : self::SUCCESS;
    }
}
