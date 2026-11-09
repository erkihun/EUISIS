<?php

declare(strict_types=1);

namespace Modules\Court\Application\Actions\Admin;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class CreateDatabaseBackupAction
{
    public function filename(): string
    {
        $appName = Str::slug((string) config('app.name', 'court-ms')) ?: 'court-ms';
        $extension = $this->driver() === 'sqlite' ? 'sqlite' : 'sql';

        return sprintf('%s-database-%s.%s', $appName, now()->format('Ymd-His'), $extension);
    }

    public function contentType(): string
    {
        return $this->driver() === 'sqlite'
            ? 'application/vnd.sqlite3'
            : 'application/sql; charset=UTF-8';
    }

    public function supportsCurrentConnection(): bool
    {
        return in_array($this->driver(), ['mysql', 'sqlite'], true);
    }

    /**
     * @param  resource  $stream
     */
    public function writeTo(mixed $stream): void
    {
        match ($this->driver()) {
            'mysql' => $this->writeMySqlDump($stream),
            'sqlite' => $this->writeSqliteDatabase($stream),
            default => throw new RuntimeException('Database backup is not supported for this connection.'),
        };
    }

    private function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    /**
     * @param  resource  $stream
     */
    private function writeMySqlDump(mixed $stream): void
    {
        $connection = DB::connection();

        $this->write($stream, "-- Court MS database backup\n");
        $this->write($stream, '-- Generated at: '.now()->toDateTimeString()."\n");
        $this->write($stream, '-- Database: '.$connection->getDatabaseName()."\n\n");
        $this->write($stream, "SET FOREIGN_KEY_CHECKS=0;\n");
        $this->write($stream, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

        foreach ($this->mysqlBaseTables($connection) as $table) {
            $quotedTable = $this->quoteMySqlIdentifier($table);
            $createRow = (array) $connection->selectOne("SHOW CREATE TABLE {$quotedTable}");
            $createSql = (string) (array_values($createRow)[1] ?? '');

            if ($createSql === '') {
                continue;
            }

            $this->write($stream, "--\n-- Table structure for {$quotedTable}\n--\n\n");
            $this->write($stream, "DROP TABLE IF EXISTS {$quotedTable};\n");
            $this->write($stream, $createSql.";\n\n");
            $this->write($stream, "--\n-- Data for {$quotedTable}\n--\n\n");

            $this->writeRows($stream, $connection, $table, $quotedTable);
            $this->write($stream, "\n");
        }

        $this->write($stream, "SET FOREIGN_KEY_CHECKS=1;\n");
    }

    /**
     * @return list<string>
     */
    private function mysqlBaseTables(Connection $connection): array
    {
        return collect($connection->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"))
            ->map(function (object $row): ?string {
                foreach ((array) $row as $column => $value) {
                    if ($column !== 'Table_type') {
                        return (string) $value;
                    }
                }

                return null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  resource  $stream
     */
    private function writeRows(mixed $stream, Connection $connection, string $table, string $quotedTable): void
    {
        $buffer = [];

        foreach ($connection->table($table)->cursor() as $row) {
            $values = array_map(
                fn (mixed $value): string => $this->sqlValue($connection, $value),
                array_values((array) $row)
            );

            $buffer[] = '('.implode(', ', $values).')';

            if (count($buffer) >= 100) {
                $this->flushInsertBuffer($stream, $quotedTable, $buffer);
                $buffer = [];
            }
        }

        $this->flushInsertBuffer($stream, $quotedTable, $buffer);
    }

    /**
     * @param  resource  $stream
     * @param  list<string>  $buffer
     */
    private function flushInsertBuffer(mixed $stream, string $quotedTable, array $buffer): void
    {
        if ($buffer === []) {
            return;
        }

        $this->write($stream, "INSERT INTO {$quotedTable} VALUES\n");
        $this->write($stream, implode(",\n", $buffer).";\n");
    }

    private function sqlValue(Connection $connection, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $connection->getPdo()->quote((string) $value);
    }

    /**
     * @param  resource  $stream
     */
    private function writeSqliteDatabase(mixed $stream): void
    {
        $databasePath = (string) config('database.connections.sqlite.database');
        $realPath = realpath($databasePath);

        if ($databasePath === ':memory:' || $realPath === false || ! is_file($realPath)) {
            throw new RuntimeException('SQLite database file could not be found.');
        }

        $source = fopen($realPath, 'rb');

        if ($source === false) {
            throw new RuntimeException('SQLite database file could not be opened.');
        }

        while (! feof($source)) {
            $chunk = fread($source, 1024 * 1024);

            if ($chunk === false) {
                break;
            }

            $this->write($stream, $chunk);
        }

        fclose($source);
    }

    private function quoteMySqlIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    /**
     * @param  resource  $stream
     */
    private function write(mixed $stream, string $contents): void
    {
        fwrite($stream, $contents);
    }
}
