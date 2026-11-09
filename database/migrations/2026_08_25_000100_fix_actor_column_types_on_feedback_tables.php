<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correct the actor columns on the feedback tables to match `users.id`.
 *
 * These were declared `uuid` on the assumption that every key in this project
 * is a UUID. Most are — but `users.id` is a bigint, so writing an actor id into
 * any of them threw "invalid input syntax for type uuid" the moment a real
 * administrator saved a record. The existing `service_types.deleted_by` column
 * shows the established convention: a plain unsigned big integer.
 *
 * Safe to run as a straight column swap: the tables carry no actor values yet
 * (every insert that would have set one failed), so nothing is lost.
 */
return new class extends Migration
{
    /** @var array<string, array<int, string>> */
    private const COLUMNS = [
        'position_services' => ['created_by', 'updated_by'],
        'employee_feedback_tokens' => ['created_by', 'revoked_by'],
        'employee_service_feedback' => ['reviewed_by'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                /*
                 * Dropping and re-adding rather than ->change(): there is no
                 * valid uuid -> bigint cast for Postgres to apply, and any
                 * stored value would be meaningless anyway.
                 */
                Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                    $blueprint->dropColumn($column);
                });

                Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                    $blueprint->unsignedBigInteger($column)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                    $blueprint->dropColumn($column);
                });

                Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                    $blueprint->uuid($column)->nullable();
                });
            }
        }
    }
};
