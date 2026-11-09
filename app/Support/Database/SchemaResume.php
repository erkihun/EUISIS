<?php

declare(strict_types=1);

namespace App\Support\Database;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Fluent;
use LogicException;
use ReflectionMethod;

/**
 * Schema changes a migration can run again after an interrupted run.
 *
 * MySQL DDL is not transactional. When a migration fails halfway, the tables,
 * columns, indexes and foreign keys it had already created stay behind while
 * the migration itself is never recorded, so every retry dies on the first
 * "already exists" and nothing after it ever runs. The live server has
 * stalled that way more than once (docs/production-branch-build rules).
 *
 * On a fresh database these helpers behave exactly like Schema::create and
 * Schema::table. Only when part of the blueprint already exists do they
 * inspect the table and add what is missing — so a retry finishes the job,
 * and every later migration gets its turn.
 */
final class SchemaResume
{
    /** Column modifiers that describe indexes; those are handled as commands. */
    private const INDEX_ATTRIBUTES = ['primary', 'unique', 'index', 'fulltext', 'fullText', 'spatialIndex', 'vectorIndex'];

    private const INDEX_METHODS = [
        'unique' => 'unique',
        'index' => 'index',
        'fulltext' => 'fullText',
        'spatialIndex' => 'spatialIndex',
        'vectorIndex' => 'vectorIndex',
    ];

    private const DROP_INDEX_COMMANDS = ['dropIndex', 'dropUnique', 'dropFullText', 'dropSpatialIndex', 'dropPrimary'];

    /**
     * Schema::create — or, when an interrupted run already created the table,
     * whatever of its columns, indexes and foreign keys are still missing.
     * Returns whether this call created the table.
     */
    public static function create(string $table, Closure $definition): bool
    {
        if (! Schema::hasTable($table)) {
            Schema::create($table, $definition);

            return true;
        }

        self::apply($table, self::intended($table, $definition));

        return false;
    }

    /**
     * Schema::table, run the ordinary way while nothing it adds exists yet,
     * and narrowed to the missing parts once some of it does.
     */
    public static function table(string $table, Closure $definition): void
    {
        $intended = Schema::hasTable($table) ? self::intended($table, $definition) : null;

        if ($intended === null || ! self::anyExists($table, $intended)) {
            Schema::table($table, $definition);

            return;
        }

        self::apply($table, $intended);
    }

    /**
     * A CHECK constraint, added once. PostgreSQL only: that is the one driver
     * these migrations declare checks on (MySQL and MariaDB differ in what
     * they accept, and the expressions use PostgreSQL casts).
     */
    public static function check(string $table, string $name, string $expression): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (! DB::pretending() && DB::table('pg_constraint')->where('conname', $name)->exists()) {
            return;
        }

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
    }

    /** The blueprint the definition describes, without running it. */
    private static function intended(string $table, Closure $definition): Blueprint
    {
        $blueprint = new Blueprint(Schema::getConnection(), $table, $definition);

        // Turn ->unique() / ->index() column modifiers into index commands, as a build would.
        (new ReflectionMethod($blueprint, 'addFluentIndexes'))->invoke($blueprint);

        return $blueprint;
    }

    /** True when any column, index or foreign key the blueprint adds is already there. */
    private static function anyExists(string $table, Blueprint $intended): bool
    {
        $state = self::state($table);

        foreach ($intended->getColumns() as $column) {
            if (! $column->change && in_array(strtolower((string) $column->name), $state['columns'], true)) {
                return true;
            }
        }

        foreach (self::commands($intended) as $command) {
            if (isset(self::INDEX_METHODS[$command->name]) && in_array(strtolower((string) $command->index), $state['indexes'], true)) {
                return true;
            }

            if ($command->name === 'foreign' && in_array(self::columnsOf($command), $state['foreignKeys'], true)) {
                return true;
            }
        }

        return false;
    }

    /** Add whatever the blueprint describes that the table does not have yet. */
    private static function apply(string $table, Blueprint $intended): void
    {
        $state = self::state($table);

        Schema::table($table, function (Blueprint $blueprint) use ($table, $intended, $state): void {
            foreach ($intended->getColumns() as $column) {
                $attributes = array_diff_key($column->getAttributes(), array_flip(self::INDEX_ATTRIBUTES));

                // A column change is repeatable; a new column is added only when absent.
                if ($column->change || ! in_array(strtolower((string) $column->name), $state['columns'], true)) {
                    $blueprint->addColumn((string) $column->type, (string) $column->name, $attributes);
                }
            }

            foreach (self::commands($intended) as $command) {
                self::applyCommand($blueprint, $table, $command, $state);
            }
        });
    }

    /** @param  array{columns: list<string>, indexes: list<string>, primary: bool, foreignKeys: list<list<string>>, foreignKeyNames: list<string>}  $state */
    private static function applyCommand(Blueprint $blueprint, string $table, Fluent $command, array $state): void
    {
        $name = strtolower((string) $command->index);

        if ($command->name === 'primary') {
            if (! $state['primary']) {
                $blueprint->primary($command->columns, $command->index);
            }

            return;
        }

        if (isset(self::INDEX_METHODS[$command->name])) {
            if (! in_array($name, $state['indexes'], true)) {
                $added = $blueprint->{self::INDEX_METHODS[$command->name]}($command->columns, $command->index);
                self::copy($command, $added, ['algorithm', 'operatorClass', 'language']);
            }

            return;
        }

        if ($command->name === 'foreign') {
            if (! in_array(self::columnsOf($command), $state['foreignKeys'], true)) {
                $added = $blueprint->foreign($command->columns, $command->index)->references($command->references)->on($command->on);
                self::copy($command, $added, ['onDelete', 'onUpdate', 'deferrable', 'initiallyImmediate', 'notValid']);
            }

            return;
        }

        if ($command->name === 'dropColumn') {
            $present = array_values(array_filter((array) $command->columns, fn (string $column): bool => in_array(strtolower($column), $state['columns'], true)));

            if ($present !== []) {
                $blueprint->dropColumn($present);
            }

            return;
        }

        if (in_array($command->name, self::DROP_INDEX_COMMANDS, true)) {
            if (in_array($name, $state['indexes'], true)) {
                $blueprint->{$command->name}($command->index);
            }

            return;
        }

        if ($command->name === 'dropForeign') {
            if (in_array($name, $state['foreignKeyNames'], true)) {
                $blueprint->dropForeign($command->index);
            }

            return;
        }

        if ($command->name === 'renameColumn') {
            if (in_array(strtolower((string) $command->from), $state['columns'], true) && ! in_array(strtolower((string) $command->to), $state['columns'], true)) {
                $blueprint->renameColumn($command->from, $command->to);
            }

            return;
        }

        throw new LogicException(sprintf("SchemaResume cannot resume a '%s' command on %s; guard it by hand.", $command->name, $table));
    }

    /** @return array{columns: list<string>, indexes: list<string>, primary: bool, foreignKeys: list<list<string>>, foreignKeyNames: list<string>} */
    private static function state(string $table): array
    {
        $indexes = collect(Schema::getIndexes($table));
        $foreignKeys = collect(Schema::getForeignKeys($table));

        return [
            'columns' => array_map('strtolower', Schema::getColumnListing($table)),
            'indexes' => $indexes->map(fn (array $index): string => strtolower((string) $index['name']))->values()->all(),
            'primary' => $indexes->contains(fn (array $index): bool => (bool) ($index['primary'] ?? false)),
            'foreignKeys' => $foreignKeys->map(fn (array $key): array => array_map('strtolower', $key['columns']))->values()->all(),
            'foreignKeyNames' => $foreignKeys->map(fn (array $key): string => strtolower((string) $key['name']))->values()->all(),
        ];
    }

    /** @return list<Fluent> the blueprint's commands, without the column definitions it also records */
    private static function commands(Blueprint $intended): array
    {
        return array_values(array_filter(
            $intended->getCommands(),
            fn (mixed $command): bool => $command instanceof Fluent && ! $command instanceof ColumnDefinition && $command->name !== 'create',
        ));
    }

    /** @return list<string> */
    private static function columnsOf(Fluent $command): array
    {
        return array_map(fn (mixed $column): string => strtolower((string) $column), (array) $command->columns);
    }

    /** @param  list<string>  $attributes */
    private static function copy(Fluent $from, Fluent $to, array $attributes): void
    {
        foreach ($attributes as $attribute) {
            if ($from->{$attribute} !== null) {
                $to->{$attribute} = $from->{$attribute};
            }
        }
    }
}
