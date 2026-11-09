<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Two Golden Path pointers had an index but no foreign key (ADR 0003):
 * employees.current_assignment_id and id_cards.previous_card_id. Assignments
 * and cards are only ever closed or replaced, never deleted, so a key with no
 * action on delete changes no application behaviour.
 *
 * Additive and safe to re-run, the same way as the positions key: a key whose
 * column has orphaned values is NOT added (the count is logged and
 * `backbone:audit-integrity` lists employees with a bad pointer); after the
 * rows are repaired, roll this migration back and run it again. SQLite (the
 * test database) cannot add a key in place and is skipped.
 */
return new class extends Migration
{
    /** table => [column, referenced table, key name] */
    private const KEYS = [
        'employees' => ['current_assignment_id', 'employee_assignments', 'employees_current_assignment_id_foreign'],
        'id_cards' => ['previous_card_id', 'id_cards', 'id_cards_previous_card_id_foreign'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach (self::KEYS as $table => [$column, $references, $name]) {
            if ($this->hasKey($table, $column)) {
                continue;
            }

            $orphans = DB::table($table.' as t')
                ->whereNotNull('t.'.$column)
                ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from($references.' as r')->whereColumn('r.id', 't.'.$column))
                ->count();

            if ($orphans > 0) {
                $message = "{$table}.{$column} foreign key NOT added: {$orphans} row(s) point at a missing {$references} row. Run backbone:audit-integrity.";
                Log::warning($message);
                echo $message.PHP_EOL;

                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($column, $references, $name): void {
                $blueprint->foreign($column, $name)->references('id')->on($references);
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach (self::KEYS as $table => [$column, , $name]) {
            if ($this->hasKey($table, $column)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($name));
            }
        }
    }

    private function hasKey(string $table, string $column): bool
    {
        return collect(Schema::getForeignKeys($table))->contains(fn (array $key): bool => $key['columns'] === [$column]);
    }
};
