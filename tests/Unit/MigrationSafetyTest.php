<?php

declare(strict_types=1);

/*
 * Migrations run against production data. From the Core go-live baseline on,
 * a migration's up() may add tables, columns and indexes, and change data
 * through reviewed code, but may not drop, truncate or rename anything.
 * Migrations up to the baseline are grandfathered (some ran in production and
 * must never be edited or deleted).
 *
 * A destructive step that has been reviewed and has its own rollout plan
 * carries the marker comment `migration-safety: reviewed` with the reason.
 */

const MIGRATION_SAFETY_BASELINE = '2026_10_25_100000';

const DESTRUCTIVE_PATTERNS = [
    'drops a table' => '/Schema::(drop|dropIfExists)\s*\(/',
    'drops a column' => '/->dropColumn\s*\(/',
    'renames a column' => '/->renameColumn\s*\(/',
    'renames a table' => '/Schema::rename\s*\(/',
    'truncates a table' => '/->truncate\s*\(|\bTRUNCATE\b/i',
    'runs DROP in SQL' => '/\bDROP\s+(TABLE|DATABASE|SCHEMA|COLUMN)\b/i',
];

/** @return list<string> what the up() method of this migration source does that is destructive */
function destructiveStepsIn(string $source): array
{
    if (str_contains($source, 'migration-safety: reviewed')) {
        return [];
    }
    // Only up(): down() is expected to undo what up() created.
    $up = preg_match('/function\s+up\s*\(.*?(?=function\s+down\s*\(|\z)/s', $source, $match) === 1 ? $match[0] : $source;

    return array_keys(array_filter(DESTRUCTIVE_PATTERNS, fn (string $pattern): bool => preg_match($pattern, $up) === 1));
}

it('recognises destructive steps only in up()', function (): void {
    $additive = "public function up(): void { Schema::create('x', fn () => null); }\npublic function down(): void { Schema::dropIfExists('x'); }";
    $dropping = "public function up(): void { Schema::table('x', fn (\$t) => \$t->dropColumn('y')); }\npublic function down(): void {}";
    $reviewed = "// migration-safety: reviewed, column unused since 2026-01 (ticket 42)\n".$dropping;

    expect(destructiveStepsIn($additive))->toBe([])
        ->and(destructiveStepsIn($dropping))->toBe(['drops a column'])
        ->and(destructiveStepsIn($reviewed))->toBe([]);
});

it('keeps every migration after the go-live baseline additive', function (): void {
    $offenders = [];
    foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [] as $file) {
        $name = basename($file, '.php');
        if (substr($name, 0, 17) <= MIGRATION_SAFETY_BASELINE) {
            continue;
        }
        foreach (destructiveStepsIn((string) file_get_contents($file)) as $step) {
            $offenders[] = $name.' '.$step;
        }
    }

    expect($offenders)->toBe([], 'A new migration is destructive. Make it additive, or give it its own reviewed plan and the marker comment.');
});
