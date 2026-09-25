<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Static discovery of permission names used by the code (docs/rbac-architecture.md §8).
 *
 * It reads authorization call sites (`->can('x')`, `Gate::…('x')`,
 * `hasPermissionTo`, `canAny([...])`, `can:`/`permission:` middleware,
 * `'permission' => 'x'` maps, scoped checks such as `inScope($u, 'x', …)`)
 * in PHP, and `can('x')` / `permission: 'x'` in the frontend.
 *
 * Names built at runtime cannot be found statically; the prefixes in
 * DYNAMIC_PREFIXES are composed that way on purpose and are reported
 * separately instead of as unused.
 */
final class PermissionUsageScanner
{
    /** Prefix => where the name is composed. */
    public const DYNAMIC_PREFIXES = [
        'public_' => 'PublicSiteManagementController / PublicSiteEditor build "{section}.{action}"',
        'system-settings.manage' => 'SystemSettingController builds "system-settings.manage".Str::studly($group)',
        'id_card_templates.' => 'SaveIdCardTemplateRequest builds "id_card_templates.{create|update}"',
    ];

    /** Role grant lists: a name listed here is granted, not checked. */
    private const GRANT_FILES = [
        'app/Support/Rbac/',
        'app/Support/DailyActivity/DailyActivityRoles.php',
        'app/Support/Performance/PerformanceRoles.php',
    ];

    private const NAME = '[a-z][a-z0-9_-]*(?:\.[a-zA-Z0-9_-]+)+';

    public function __construct(private readonly string $root = '') {}

    /**
     * Permission names referenced by authorization code, with the files that reference them.
     *
     * @return array<string, list<string>>
     */
    public function references(): array
    {
        $n = self::NAME;
        $php = [
            // A literal that is concatenated ('x.'.$y) is a prefix of a dynamic name, not a permission.
            "/->(?:can|cannot|cant)\\(\\s*'({$n})'(?!\\s*\\.)/",
            "/Gate::(?:forUser\\([^)]*\\)->)?(?:allows|denies|authorize|check|inspect|any|none)\\(\\s*'({$n})'/",
            "/->(?:authorize|allows|denies)\\(\\s*'({$n})'/",
            "/->(?:hasPermissionTo|checkPermissionTo|hasDirectPermission)\\(\\s*'({$n})'/",
            "/(?:inScope|canExercisePermission)\\([^,()]*,\\s*'({$n})'/",
            "/'permission'\\s*=>\\s*'({$n})'/",
        ];
        $lists = [
            '/->(?:canAny|hasAnyPermission|hasAllPermissions)\\(\\s*\\[([^\\]]*)\\]/',
            "/['\"](?:can|permission|role_or_permission):([^'\"]+)['\"]/",
        ];
        $frontend = [
            "/\\bcan\\(\\s*['\"]({$n})['\"]/",
            "/permission:\\s*['\"]({$n})['\"]/",
            "/permissions\\.includes\\(\\s*['\"]({$n})['\"]/",
        ];

        $refs = [];
        foreach ($this->files(['app', 'routes', 'config'], '.php') as $path => $src) {
            if ($this->isGrantFile($path)) {
                continue;
            }
            foreach ($php as $re) {
                if (preg_match_all($re, $src, $m)) {
                    foreach ($m[1] as $name) {
                        $refs[$name][$path] = true;
                    }
                }
            }
            foreach ($lists as $re) {
                if (preg_match_all($re, $src, $m)) {
                    foreach ($m[1] as $block) {
                        preg_match_all("/({$n})/", $block, $names);
                        foreach ($names[1] as $name) {
                            $refs[$name][$path] = true;
                        }
                    }
                }
            }
            // Gate lists such as `private const PERMISSIONS = [...]` used with canAny(self::PERMISSIONS).
            if (preg_match_all('/const\s+[A-Z_]*PERMISSIONS\s*=\s*\[(.*?)\];/s', $src, $m)) {
                foreach ($m[1] as $block) {
                    preg_match_all("/'({$n})'/", $block, $names);
                    foreach ($names[1] as $name) {
                        $refs[$name][$path] = true;
                    }
                }
            }
        }
        foreach ($this->files(['resources/js'], '.ts', '.tsx') as $path => $src) {
            foreach ($frontend as $re) {
                if (preg_match_all($re, $src, $m)) {
                    foreach ($m[1] as $name) {
                        $refs[$name][$path] = true;
                    }
                }
            }
        }

        ksort($refs);

        return array_map(fn (array $files): array => array_keys($files), $refs);
    }

    /** @return list<string> referenced by code but missing from the catalog */
    public function missingFromCatalog(): array
    {
        return array_values(array_filter(array_keys($this->references()), fn (string $name): bool => ! PermissionCatalog::has($name)));
    }

    /**
     * Catalog permissions never mentioned outside grant lists (not even as a
     * string literal), excluding dynamically composed ones.
     *
     * @return list<string>
     */
    public function unusedCatalogEntries(): array
    {
        $corpus = '';
        foreach ([...$this->files(['app', 'routes', 'config'], '.php'), ...$this->files(['resources/js'], '.ts', '.tsx')] as $path => $src) {
            if (! $this->isGrantFile($path)) {
                $corpus .= $src."\n";
            }
        }

        return array_values(array_filter(PermissionCatalog::names(), function (string $name) use ($corpus): bool {
            return $this->dynamicSource($name) === null
                && ! str_contains($corpus, "'{$name}'")
                && ! str_contains($corpus, "\"{$name}\"");
        }));
    }

    public function dynamicSource(string $name): ?string
    {
        foreach (self::DYNAMIC_PREFIXES as $prefix => $source) {
            if (str_starts_with($name, $prefix)) {
                return $source;
            }
        }

        return null;
    }

    private function isGrantFile(string $path): bool
    {
        foreach (self::GRANT_FILES as $grant) {
            if (str_starts_with($path, $grant)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> relative path => contents */
    private function files(array $dirs, string ...$extensions): array
    {
        $base = rtrim($this->root !== '' ? $this->root : base_path(), '/\\').'/';
        $out = [];
        foreach ($dirs as $dir) {
            if (! is_dir($base.$dir)) {
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base.$dir, FilesystemIterator::SKIP_DOTS)) as $file) {
                $path = str_replace('\\', '/', substr((string) $file, strlen($base)));
                foreach ($extensions as $extension) {
                    if (str_ends_with($path, $extension)) {
                        $out[$path] = (string) file_get_contents((string) $file);
                        break;
                    }
                }
            }
        }

        return $out;
    }
}
