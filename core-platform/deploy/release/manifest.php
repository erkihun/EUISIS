<?php

declare(strict_types=1);

/*
 * The release manifest (docs/go-live/core/core-release-manifest.md), generated from an
 * unpacked release, never typed by hand:
 *
 *   cd <release>/backend && php ../../path/to/manifest.php [database version] > manifest.md
 *
 * Commit and tag (RELEASE), PHP, Laravel and key package versions (composer.lock),
 * the frontend build hash, every migration, every environment variable the
 * configuration reads, the scheduled commands and the workers.
 */

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$release = is_file('../RELEASE') ? file('../RELEASE', FILE_IGNORE_NEW_LINES) : ['(not an artifact: no RELEASE file)'];
$meta = [];
foreach (array_slice($release, 1) as $line) {
    [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
    $meta[$k] = $v;
}
$lock = json_decode((string) file_get_contents('composer.lock'), true);
$packages = collect($lock['packages'] ?? [])->keyBy('name');
$version = fn (string $name): string => (string) ($packages[$name]['version'] ?? '-');
$migrations = array_map(fn (string $f): string => basename($f, '.php'), glob('database/migrations/*.php'));
sort($migrations);
$env = [];
foreach (glob('config/*.php') as $file) {
    preg_match_all("/env\\(\\s*'([A-Z0-9_]+)'/", (string) file_get_contents($file), $m);
    foreach ($m[1] as $name) {
        $env[$name][] = basename($file, '.php');
    }
}
ksort($env);
$required = ['APP_KEY', 'APP_URL', 'APP_ENV', 'APP_DEBUG', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'SESSION_SECURE_COOKIE',
    'QUEUE_CONNECTION', 'CACHE_STORE', 'SESSION_DRIVER', 'CORE_MFA_ENFORCE', 'CORE_CREDENTIAL_KEYS', 'CORE_AUDIT_SEAL_KEY', 'NATIONAL_ID_HASH_KEY', 'CORE_AUDIT_HEAD_DISK',
    'CORE_BACKUP_STATUS_FILE', 'CORE_MONITOR_TOKEN', 'TRUSTED_PROXIES', 'CORE_FRONTEND_ORIGINS', 'MAIL_MAILER'];
$secret = ['APP_KEY', 'DB_PASSWORD', 'CORE_CREDENTIAL_KEYS', 'CORE_AUDIT_SEAL_KEY', 'NATIONAL_ID_HASH_KEY', 'CORE_MONITOR_TOKEN', 'MAIL_PASSWORD', 'EUISIS_DB_PASSWORD', 'AWS_SECRET_ACCESS_KEY', 'REDIS_PASSWORD'];
$schedule = collect(app(Schedule::class)->events())->map(fn ($e): array => [trim(explode('artisan', (string) $e->command)[1] ?? (string) $e->command, " '\""), $e->expression,
    $e->withoutOverlapping ? 'yes' : 'NO', $e->onOneServer ? 'yes' : 'NO']);

$out = [];
$out[] = '# Core release manifest';
$out[] = '';
$out[] = '| Item | Value |';
$out[] = '|---|---|';
$out[] = '| Git commit | `'.$release[0].'` |';
$out[] = '| Release tag | '.($meta['tag'] ?? '-').' |';
$out[] = '| Built at (UTC) | '.($meta['built_at'] ?? '-').' |';
$out[] = '| PHP (build) | '.($meta['php'] ?? PHP_VERSION).' — servers: PHP 8.4 |';
$out[] = '| Node (build) | '.($meta['node'] ?? '-').' |';
$out[] = '| Laravel | '.$version('laravel/framework').' |';
$out[] = '| Inertia (server) | '.$version('inertiajs/inertia-laravel').' |';
$out[] = '| Sanctum | '.$version('laravel/sanctum').' |';
$out[] = '| Database | '.($argv[1] ?? 'PostgreSQL 18 (rehearsed on 18.6)').' |';
$out[] = '| composer.lock SHA-256 | `'.($meta['composer_lock_sha256'] ?? hash_file('sha256', 'composer.lock')).'` |';
$out[] = '| Frontend build manifest SHA-256 | `'.($meta['frontend_manifest_sha256'] ?? (is_file('public/build/manifest.json') ? hash_file('sha256', 'public/build/manifest.json') : '-')).'` |';
$out[] = '| Migrations | '.count($migrations).' |';
$out[] = '';
$out[] = '## Migrations';
$out[] = '';
foreach ($migrations as $migration) {
    $out[] = "- `{$migration}`";
}
$out[] = '';
$out[] = '## Environment variables';
$out[] = '';
$out[] = 'Required in production are marked **required**; secrets come from the secret store, never the artifact.';
$out[] = '';
$out[] = '| Variable | Read by | Production |';
$out[] = '|---|---|---|';
foreach ($env as $name => $files) {
    $out[] = "| `{$name}` | ".implode(', ', array_unique($files)).' | '.(in_array($name, $required, true) ? '**required**' : 'optional').(in_array($name, $secret, true) ? ' (secret)' : '').' |';
}
$out[] = '';
$out[] = '## Scheduled commands (cron: `* * * * * php artisan schedule:run`)';
$out[] = '';
$out[] = '| Command | Cron | Overlap protected | One server |';
$out[] = '|---|---|---|---|';
foreach ($schedule as [$command, $cron, $overlap, $one]) {
    $out[] = "| `{$command}` | `{$cron}` | {$overlap} | {$one} |";
}
$out[] = '';
$out[] = '## Workers';
$out[] = '';
$out[] = '- `php artisan queue:work database --queue=default --tries=3 --max-time=3600 --memory=256`, at least 2 processes under systemd/supervisor; `queue:restart` on every deploy.';
$out[] = '- No Horizon (no Redis). The cache, sessions and queue use the database.';
echo implode("\n", $out)."\n";
