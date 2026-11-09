<?php

declare(strict_types=1);

/*
 * `php -S` router that serves the app like public/index.php but appends every
 * SQL statement of every request to storage/logs/bench/queries.log (one block
 * per request). Benchmark diagnostics only: shows where per-request statements
 * come from (docs/production-readiness/load-test-results.md, statements per request).
 *
 *   cd public && php -S 127.0.0.1:8199 ../loadtest/profile/query-log-router.php
 */

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 2);
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
if ($uri !== '/' && is_file($root.'/public'.$uri)) {
    return false;
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$log = [];
$app->booted(function () use (&$log): void {
    DB::listen(function ($query) use (&$log): void {
        $log[] = sprintf('%6.2fms %s', $query->time, preg_replace('/\s+/', ' ', $query->sql));
    });
});
register_shutdown_function(function () use (&$log, $root, $uri): void {
    file_put_contents($root.'/storage/logs/bench/queries.log', '### '.($_SERVER['REQUEST_METHOD'] ?? 'GET').' '.$uri.' — '.count($log)." statements\n".implode("\n", $log)."\n\n", FILE_APPEND);
});
$app->handleRequest(Request::capture());
