<?php

// Standalone, read-only application smoke check. Invoked only by the isolated restore runner.
// Values read from employee records are never printed, even on failure.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
try {
    if (count($argv) !== 6 || ! is_dir($argv[1]) || ! str_ends_with($argv[1], '/socket')) {
        throw new RuntimeException('Isolated socket required');
    }
    // Providers read system settings during boot. Isolate BEFORE any provider can
    // resolve DB/cache; a copied config cache must never reach its original hosts.
    $app->afterBootstrapping(LoadConfiguration::class, function ($app) use ($argv): void {
        $app['config']->set([
            'database.default' => 'restore_check',
            'database.connections' => ['restore_check' => [
                'driver' => 'pgsql', 'host' => $argv[1], 'port' => $argv[2], 'database' => $argv[3],
                'username' => $argv[4], 'password' => '', 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public',
            ]],
            'database.redis' => [],
            'cache.default' => 'array', 'cache.stores' => ['array' => ['driver' => 'array', 'serialize' => false]],
            'session.driver' => 'array', 'queue.default' => 'null', 'queue.connections' => [],
            'mail.default' => 'array', 'mail.mailers' => ['array' => ['transport' => 'array']],
            'logging.default' => 'null',
            'filesystems.disks' => [
                'local' => ['driver' => 'local', 'root' => $argv[5].'/private'],
                'public' => ['driver' => 'local', 'root' => $argv[5].'/public'],
            ],
        ]);
    });
    $app->make(Kernel::class)->bootstrap();
    DB::purge('restore_check');
    DB::beginTransaction();
    DB::statement('SET TRANSACTION READ ONLY');
    if (DB::selectOne('select pg_is_in_recovery() as recovering')->recovering) {
        throw new RuntimeException('PITR not finished');
    }
    foreach (['migrations', 'organizations', 'organization_units', 'positions', 'employees', 'employee_assignments', 'id_cards', 'service_providers', 'cafeteria_transactions'] as $table) {
        if (! Schema::hasTable($table)) {
            throw new RuntimeException('Missing table');
        }
        DB::table($table)->limit(1)->get();
    }
    $migrator = app('migrator');
    $files = $migrator->getMigrationFiles(database_path('migrations'));
    $ran = $migrator->getRepository()->getRan();
    if (array_diff(array_keys($files), $ran) || array_diff($ran, array_keys($files))) {
        throw new RuntimeException('Release and schema differ');
    }
    if (DB::selectOne("select count(*) as invalid from pg_constraint where connamespace = 'public'::regnamespace and not convalidated")->invalid > 0) {
        throw new RuntimeException('Unvalidated constraints');
    }
    foreach ([['employee_assignments', 'employee_id', 'employees'], ['id_cards', 'employee_id', 'employees']] as [$table, $fk, $parent]) {
        if (DB::table($table.' as child')->leftJoin($parent.' as parent', 'child.'.$fk, '=', 'parent.id')->whereNotNull('child.'.$fk)->whereNull('parent.id')->exists()) {
            throw new RuntimeException('Orphan reference');
        }
    }
    // Bypass the legacy accessor's plaintext fallback: recovery must prove decryption.
    foreach (DB::table('employees')->whereNotNull('national_id')->limit(50)->pluck('national_id') as $ciphertext) {
        decrypt($ciphertext);
    }
    $root = realpath($argv[5]);
    if (! $root) {
        throw new RuntimeException('Restored file storage required');
    }
    foreach (DB::table('employee_documents')->limit(50)->get(['file_path', 'storage_disk']) as $document) {
        $disk = $document->storage_disk ?: 'local';
        if (! in_array($disk, ['local', 'public'], true)) {
            throw new RuntimeException('Object storage validation requires approved adapter');
        }
        $path = realpath($root.'/'.($disk === 'public' ? 'public' : 'private').'/'.$document->file_path);
        if (! $path || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || ! is_readable($path)) {
            throw new RuntimeException('Missing recovered document');
        }
    }
    DB::rollBack();
    echo "ISOLATED_APPLICATION_CHECK_PASSED\n";
} catch (Throwable) {
    fwrite(STDERR, "ISOLATED_APPLICATION_CHECK_FAILED\n");
    exit(1);
}
