<?php

declare(strict_types=1);

/*
 * Core Stage 6 CI/CD safety: no workflow, deployment or release script may wipe
 * or rebuild a database, and the application refuses the destructive artisan
 * commands in production (DB::prohibitDestructiveCommands in AppServiceProvider).
 */

const DESTRUCTIVE_DEPLOY_PATTERNS = [
    'migrate:fresh' => '/\bmigrate:fresh\b/',
    'migrate:refresh' => '/\bmigrate:refresh\b/',
    'migrate:reset' => '/\bmigrate:reset\b/',
    'db:wipe' => '/\bdb:wipe\b/',
    'DROP TABLE / DATABASE' => '/\bDROP\s+(TABLE|DATABASE|SCHEMA)\b/i',
    'TRUNCATE' => '/\bTRUNCATE\b/i',
];

it('keeps destructive database commands out of workflows and deployment scripts', function (): void {
    $root = dirname(__DIR__, 2);
    $offenders = [];
    foreach (['.github', 'deploy', 'scripts'] as $folder) {
        if (! is_dir($root.'/'.$folder)) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$folder, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! in_array($file->getExtension(), ['yml', 'yaml', 'sh', 'ps1', 'bat', 'mjs', 'js', 'php', ''], true)) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            foreach (DESTRUCTIVE_DEPLOY_PATTERNS as $label => $pattern) {
                if (preg_match($pattern, $source) === 1) {
                    $offenders[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1)).': '.$label;
                }
            }
        }
    }

    expect($offenders)->toBe([], 'A workflow or deployment script runs a destructive database command.');
});

it('refuses the destructive artisan commands in production', function (): void {
    $provider = (string) file_get_contents(dirname(__DIR__, 2).'/app/Providers/AppServiceProvider.php');

    expect($provider)->toContain('DB::prohibitDestructiveCommands($this->app->isProduction())');
});
