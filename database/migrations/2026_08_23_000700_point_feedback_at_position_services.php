<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repoint client feedback from entitlement types to position services.
 *
 * Feedback is about work an officer PERFORMED, so it must reference the
 * position service delivered — not `service_types`, which catalogs what an
 * employee is entitled to receive. The original column was modelled on that
 * wrong assumption.
 *
 * `service_type_id` is dropped rather than kept nullable: no feedback rows
 * exist yet, and leaving a dead foreign key would invite future code to join
 * the entitlements catalog again by mistake.
 *
 * The snapshots make each row self-describing. A service can be renamed,
 * renumbered or deactivated long after a client rated it, and a performance
 * report that joined live would silently rewrite history.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * The original table carried both a foreign key on `service_type_id`
         * and an index on ['service_type_id', 'rating']. Three engines disagree
         * on the order these may be removed in, so all three are satisfied by
         * going strictly foreign key -> index -> column:
         *
         *  - MySQL refuses to drop the index while the foreign key still needs
         *    it ("errno 1553"), so the constraint must go first.
         *  - SQLite refuses to drop a column an index still references, so the
         *    index must go before the column.
         *  - Postgres accepts any order.
         *
         * Each step is guarded because a database may legitimately be missing
         * one of them — a fresh SQLite test schema names indexes differently,
         * and re-running after a partial failure must not abort.
         */
        if (Schema::hasColumn('employee_service_feedback', 'service_type_id')) {
            $this->dropForeignKeyIfExists('employee_service_feedback', 'service_type_id');
            $this->dropIndexIfExists('employee_service_feedback', 'employee_service_feedback_service_type_id_rating_index');

            Schema::table('employee_service_feedback', function (Blueprint $table): void {
                $table->dropColumn('service_type_id');
            });
        }

        Schema::table('employee_service_feedback', function (Blueprint $table): void {
            $table->foreignUuid('position_service_id')
                ->nullable()
                ->after('position_id')
                ->constrained('position_services')
                ->nullOnDelete();

            $table->string('service_no_snapshot', 40)->nullable()->after('position_service_id');
            $table->string('service_name_snapshot')->nullable()->after('service_no_snapshot');
        });

        Schema::table('employee_service_feedback', function (Blueprint $table): void {
            // Performance reporting: by service within an organization, and by
            // employee across the services their post delivers.
            $table->index(['organization_id', 'service_no_snapshot'], 'esf_org_service_no_index');
            $table->index(['employee_id', 'service_no_snapshot'], 'esf_employee_service_no_index');
        });
    }

    public function down(): void
    {
        /*
         * Mirrors up(): the foreign key is released before anything that MySQL
         * might consider its supporting index, and each drop is guarded so a
         * partially-applied schema can still be reversed.
         */
        $this->dropForeignKeyIfExists('employee_service_feedback', 'position_service_id');
        $this->dropIndexIfExists('employee_service_feedback', 'esf_org_service_no_index');
        $this->dropIndexIfExists('employee_service_feedback', 'esf_employee_service_no_index');

        Schema::table('employee_service_feedback', function (Blueprint $table): void {
            $table->dropColumn(['position_service_id', 'service_no_snapshot', 'service_name_snapshot']);
        });

        Schema::table('employee_service_feedback', function (Blueprint $table): void {
            $table->foreignUuid('service_type_id')->nullable()->constrained('service_types');
            $table->index(['service_type_id', 'rating']);
        });
    }

    /**
     * Drop a foreign key only if the database actually has one.
     *
     * SQLite has no named foreign keys to introspect and rebuilds the table on
     * column drop, so it is skipped outright. Elsewhere the constraint name is
     * looked up rather than guessed, because a schema created by an older
     * migration may not follow Laravel's current naming convention.
     */
    private function dropForeignKeyIfExists(string $table, string $column): void
    {
        $matching = array_filter(
            Schema::getForeignKeys($table),
            static fn (array $foreignKey): bool => in_array($column, $foreignKey['columns'] ?? [], true),
        );

        if ($matching === []) {
            return;
        }

        foreach ($matching as $foreignKey) {
            Schema::table($table, function (Blueprint $blueprint) use ($foreignKey, $column): void {
                /*
                 * SQLite reports foreign keys without a usable name, and
                 * dropping one there means rebuilding the table — which Laravel
                 * only does when given the column form. MySQL and Postgres take
                 * the real constraint name, which may not follow Laravel's
                 * current convention on an older schema.
                 */
                if (DB::getDriverName() === 'sqlite' || empty($foreignKey['name'])) {
                    $blueprint->dropForeign([$column]);

                    return;
                }

                $blueprint->dropForeign($foreignKey['name']);
            });
        }
    }

    /** Drop an index only if it is present under that name. */
    private function dropIndexIfExists(string $table, string $index): void
    {
        $names = array_map(
            static fn (array $definition): string => (string) ($definition['name'] ?? ''),
            Schema::getIndexes($table),
        );

        if (! in_array($index, $names, true)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($index): void {
            $blueprint->dropIndex($index);
        });
    }
};
