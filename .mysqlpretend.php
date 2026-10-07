<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.fakemysql' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'x', 'username' => 'x', 'password' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'engine' => null]]);
config(['database.default' => 'fakemysql']);
$files = array_slice($argv, 1);
foreach ($files as $file) {
    $migration = require $file;
    $conn = Illuminate\Support\Facades\DB::connection('fakemysql');
    $conn->setPdo(new PDO('sqlite::memory:'))->setReadPdo(new PDO('sqlite::memory:'));
    $queries = $conn->pretend(fn () => $migration->up());
    foreach ($queries as $q) { echo $q['query'], ";\n"; }
}
