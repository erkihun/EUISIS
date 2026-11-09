<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Employee;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Throwable;

/**
 * Finds encrypted column values the current keys cannot read: every model
 * column with an `encrypted…` cast, plus employees.national_id (encrypted by
 * its own mutator). A value written under an earlier APP_KEY fails with "The
 * MAC is invalid" and breaks every page that reads it; APP_PREVIOUS_KEYS with
 * that key makes it readable again, which is always the first remedy.
 *
 * Only when the old key is gone for good, --clear sets those values to NULL so
 * the pages work and the data can be entered again. Values stored before
 * encryption (plaintext) are reported, never cleared:
 * sensitive-data:encrypt-existing handles those.
 *
 * Usage:
 *   php artisan security:encrypted-values                  # report only
 *   php artisan security:encrypted-values --clear --force  # clear unreadable values
 */
class EncryptedValuesHealth extends Command
{
    protected $signature = 'security:encrypted-values
        {--clear : Set values encrypted with an unknown key to NULL}
        {--force : Clear without asking (for non-interactive runners)}
        {--chunk=500 : Rows per batch}';

    protected $description = 'Report (and optionally clear) encrypted values the current APP_KEY and APP_PREVIOUS_KEYS cannot decrypt';

    public function handle(): int
    {
        $clear = (bool) $this->option('clear');
        $chunk = max(1, (int) $this->option('chunk'));

        if ($clear && ! $this->option('force') && ! $this->confirm('Values encrypted with an unknown key will be set to NULL for good. Is the old key really lost?')) {
            return self::FAILURE;
        }

        $rows = [];
        $unreadableTotal = 0;
        foreach ($this->columns() as [$table, $key, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $stats = ['readable' => 0, 'unreadable' => 0, 'plaintext' => 0, 'cleared' => 0];
            DB::table($table)->whereNotNull($column)->select([$key, $column])->orderBy($key)
                ->chunkById($chunk, function ($records) use ($table, $key, $column, $clear, &$stats): void {
                    $clearIds = [];
                    foreach ($records as $record) {
                        $state = self::state((string) $record->{$column});
                        $stats[$state]++;
                        if ($state === 'unreadable') {
                            $clearIds[] = $record->{$key};
                        }
                    }
                    if ($clear && $clearIds !== []) {
                        $stats['cleared'] += DB::table($table)->whereIn($key, $clearIds)->update([$column => null]);
                    }
                }, $key);

            $unreadableTotal += $stats['unreadable'];
            $rows[] = ["{$table}.{$column}", $stats['readable'], $stats['unreadable'], $stats['plaintext'], $clear ? $stats['cleared'] : '-'];
        }

        $this->table(['Column', 'Readable', 'Unknown key', 'Plaintext', 'Cleared'], $rows);

        if ($unreadableTotal === 0) {
            $this->components->info('Every encrypted value is readable with the current keys.');

            return self::SUCCESS;
        }

        if ($clear) {
            $this->components->warn("{$unreadableTotal} value(s) were cleared. Run security:rehash-national-ids, then ask the people concerned to enter the data again.");

            return self::SUCCESS;
        }

        $this->components->error("{$unreadableTotal} value(s) were encrypted with a key this server does not have. Add that key to APP_PREVIOUS_KEYS; if it is lost for good, rerun with --clear --force.");

        return self::FAILURE;
    }

    /**
     * readable: decrypts with APP_KEY or APP_PREVIOUS_KEYS; unreadable: an
     * encrypted payload no known key opens; plaintext: not encrypted at all.
     */
    public static function state(string $value): string
    {
        $payload = json_decode((string) base64_decode($value, true), true);
        if (! is_array($payload) || ! isset($payload['iv'], $payload['value'])) {
            return 'plaintext';
        }

        try {
            Crypt::decryptString($value);

            return 'readable';
        } catch (DecryptException) {
            return 'unreadable';
        }
    }

    /**
     * [table, key column, column] for every encrypted column.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function columns(): array
    {
        $columns = [];
        foreach ($this->models() as $model) {
            foreach ($model->getCasts() as $column => $cast) {
                if (is_string($cast) && str_starts_with($cast, 'encrypted')) {
                    $columns[] = [$model->getTable(), $model->getKeyName(), $column];
                }
            }
        }
        // Encrypted by Employee::setNationalIdAttribute, not by a cast.
        $employee = new Employee;
        $columns[] = [$employee->getTable(), $employee->getKeyName(), 'national_id'];

        return array_values(array_unique($columns, SORT_REGULAR));
    }

    /** @return list<Model> */
    private function models(): array
    {
        $files = array_merge(
            glob(app_path('Models/*.php')) ?: [],
            glob(app_path('Models/*/*.php')) ?: [],
            glob(base_path('Modules/*/Domain/Models/*.php')) ?: [],
        );

        $models = [];
        foreach ($files as $file) {
            [$path, $app, $base] = array_map(fn (string $p): string => str_replace('\\', '/', $p), [$file, app_path(), base_path()]);
            $relative = str_starts_with($path, $app.'/') ? 'App/'.substr($path, strlen($app) + 1) : substr($path, strlen($base) + 1);
            $class = str_replace(['/', '.php'], ['\\', ''], $relative);
            try {
                if (! class_exists($class)) {
                    continue;
                }
                $reflection = new ReflectionClass($class);
                if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                    continue;
                }
                $models[] = $reflection->newInstance();
            } catch (Throwable) {
                continue;
            }
        }

        return $models;
    }
}
