<?php

declare(strict_types=1);

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Workflow infrastructure for public submissions (brief §45–53): routing
 * rules, an append-only status history and an assignment history. Additive;
 * existing rows keep their status and gain history from their next change.
 *
 * Resumable. MySQL DDL is not transactional, and on the live MySQL server a
 * first run created the routing-rule and assignment tables and then failed,
 * leaving them behind with the migration unrecorded, so every retry stopped
 * at "table already exists". Each step now skips what is already there and
 * adds only what is missing (tables, keys, indexes, the open-assignment
 * guard), so a retry finishes the job on any database.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'service_feedback.assign',
        'service_feedback.reassign',
        'service_feedback.resolve',
        'service_feedback.close',
        'service_feedback.manage_routing',
    ];

    public function up(): void
    {
        $rulesCreated = $this->createOrComplete('public_feedback_routing_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Null = city-wide rule; otherwise the institution the rule serves.
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->string('target_type', 32)->nullable();
            $table->string('submission_type', 32)->nullable();
            $table->foreignUuid('category_id')->nullable()->constrained('public_feedback_categories')->restrictOnDelete();
            $table->string('handler_type', 32);
            $table->string('handler_id', 64);
            $table->unsignedInteger('priority')->default(100);
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'is_active', 'priority'], 'pfrr_org_active_priority_idx');
        }, foreignKeys: [
            'organization_id' => fn (Blueprint $table) => $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete(),
            'category_id' => fn (Blueprint $table) => $table->foreign('category_id')->references('id')->on('public_feedback_categories')->restrictOnDelete(),
            'created_by' => fn (Blueprint $table) => $table->foreign('created_by')->references('id')->on('users')->nullOnDelete(),
            'updated_by' => fn (Blueprint $table) => $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete(),
        ], indexes: [
            'pfrr_org_active_priority_idx' => fn (Blueprint $table) => $table->index(['organization_id', 'is_active', 'priority'], 'pfrr_org_active_priority_idx'),
        ]);

        $assignmentsCreated = $this->createOrComplete('public_feedback_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('submission_id')->constrained('employee_service_feedback')->cascadeOnDelete();
            $table->string('handler_type', 32);
            $table->string('handler_id', 64);
            $table->string('handler_name_snapshot');
            $table->string('handler_name_am_snapshot')->nullable();
            $table->foreignUuid('routing_rule_id')->nullable()->constrained('public_feedback_routing_rules')->nullOnDelete();
            // Null = assigned by a routing rule, not by a person.
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->dateTime('assigned_at');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['submission_id', 'assigned_at'], 'pfa_submission_assigned_idx');
            $table->index(['handler_type', 'handler_id', 'ended_at'], 'pfa_handler_open_idx');
        }, foreignKeys: [
            'submission_id' => fn (Blueprint $table) => $table->foreign('submission_id')->references('id')->on('employee_service_feedback')->cascadeOnDelete(),
            'routing_rule_id' => fn (Blueprint $table) => $table->foreign('routing_rule_id')->references('id')->on('public_feedback_routing_rules')->nullOnDelete(),
            'assigned_by' => fn (Blueprint $table) => $table->foreign('assigned_by')->references('id')->on('users')->nullOnDelete(),
            'ended_by' => fn (Blueprint $table) => $table->foreign('ended_by')->references('id')->on('users')->nullOnDelete(),
        ], indexes: [
            'pfa_submission_assigned_idx' => fn (Blueprint $table) => $table->index(['submission_id', 'assigned_at'], 'pfa_submission_assigned_idx'),
            'pfa_handler_open_idx' => fn (Blueprint $table) => $table->index(['handler_type', 'handler_id', 'ended_at'], 'pfa_handler_open_idx'),
        ]);

        $this->ensureOneOpenAssignmentPerSubmission();

        $this->createOrComplete('public_feedback_status_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('submission_id')->constrained('employee_service_feedback')->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            // Null = the system (submission, routing).
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->dateTime('transitioned_at');

            $table->index(['submission_id', 'transitioned_at'], 'pfsh_submission_time_idx');
        }, foreignKeys: [
            'submission_id' => fn (Blueprint $table) => $table->foreign('submission_id')->references('id')->on('employee_service_feedback')->cascadeOnDelete(),
            'actor_id' => fn (Blueprint $table) => $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete(),
        ], indexes: [
            'pfsh_submission_time_idx' => fn (Blueprint $table) => $table->index(['submission_id', 'transitioned_at'], 'pfsh_submission_time_idx'),
        ]);

        // PostgreSQL only; a table created by an earlier run already carries its checks.
        if (DB::getDriverName() === 'pgsql') {
            if ($rulesCreated) {
                DB::statement("ALTER TABLE public_feedback_routing_rules ADD CONSTRAINT pfrr_handler_type_chk CHECK (handler_type IN ('user', 'organization_unit', 'role'))");
                DB::statement("ALTER TABLE public_feedback_routing_rules ADD CONSTRAINT pfrr_target_type_chk CHECK (target_type IS NULL OR target_type IN ('employee', 'organization'))");
                DB::statement("ALTER TABLE public_feedback_routing_rules ADD CONSTRAINT pfrr_submission_type_chk CHECK (submission_type IS NULL OR submission_type IN ('suggestion', 'complaint'))");
                DB::statement('ALTER TABLE public_feedback_routing_rules ADD CONSTRAINT pfrr_effective_window_chk CHECK (effective_from IS NULL OR effective_to IS NULL OR effective_to >= effective_from)');
            }

            if ($assignmentsCreated) {
                DB::statement("ALTER TABLE public_feedback_assignments ADD CONSTRAINT pfa_handler_type_chk CHECK (handler_type IN ('user', 'organization_unit', 'role'))");
            }
        }

        if (DB::pretending()) {
            return;
        }

        $catalog = require database_path('seeders/data/permissions.php');

        foreach ($catalog as $entry) {
            if (in_array($entry['name'], self::PERMISSIONS, true)) {
                Permission::firstOrCreate(['name' => $entry['name'], 'guard_name' => 'web'], $entry);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('public_feedback_status_history');
        Schema::dropIfExists('public_feedback_assignments');
        Schema::dropIfExists('public_feedback_routing_rules');
    }

    /**
     * Create the table, or — when an interrupted run already created it —
     * add whichever of its foreign keys and indexes are missing. Returns
     * whether the table was created now.
     *
     * @param  array<string, Closure(Blueprint): mixed>  $foreignKeys  keyed by the constrained column
     * @param  array<string, Closure(Blueprint): mixed>  $indexes  keyed by index name
     */
    private function createOrComplete(string $table, Closure $definition, array $foreignKeys, array $indexes): bool
    {
        if (! Schema::hasTable($table)) {
            Schema::create($table, $definition);

            return true;
        }

        if (DB::pretending()) {
            return false;
        }

        $missingKeys = array_filter($foreignKeys, fn (string $column): bool => ! $this->hasForeignKey($table, $column), ARRAY_FILTER_USE_KEY);
        $missingIndexes = array_filter($indexes, fn (string $name): bool => ! $this->hasIndex($table, $name), ARRAY_FILTER_USE_KEY);

        if ($missingKeys !== [] || $missingIndexes !== []) {
            Schema::table($table, function (Blueprint $blueprint) use ($missingKeys, $missingIndexes): void {
                foreach ([...$missingIndexes, ...$missingKeys] as $add) {
                    $add($blueprint);
                }
            });
        }

        return false;
    }

    /**
     * At most one open assignment per submission.
     *
     * PostgreSQL and SQLite: a partial unique index. MySQL and MariaDB have no
     * partial indexes, so a generated column carries the submission id of open
     * rows only and a unique index sits on it. The column is VIRTUAL: MySQL
     * refuses a STORED generated column whose base column (submission_id) has
     * a cascading foreign key, which is what failed on the live server.
     *
     * Should the server still refuse the index, the migration carries on
     * without it rather than block every later deploy: the assignment service
     * ends the previous assignment before opening a new one, and
     * `php artisan feedback:data-quality` reports any submission with more
     * than one open assignment.
     */
    private function ensureOneOpenAssignmentPerSubmission(): void
    {
        if ($this->hasIndex('public_feedback_assignments', 'pfa_one_open_per_submission')) {
            return;
        }

        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('CREATE UNIQUE INDEX pfa_one_open_per_submission ON public_feedback_assignments (submission_id) WHERE ended_at IS NULL');

            return;
        }

        try {
            if (! Schema::hasColumn('public_feedback_assignments', 'open_submission_id')) {
                DB::statement('ALTER TABLE public_feedback_assignments ADD COLUMN open_submission_id CHAR(36) GENERATED ALWAYS AS (CASE WHEN ended_at IS NULL THEN submission_id ELSE NULL END) VIRTUAL');
            }

            DB::statement('CREATE UNIQUE INDEX pfa_one_open_per_submission ON public_feedback_assignments (open_submission_id)');
        } catch (QueryException $exception) {
            Log::warning('public_feedback_assignments: one-open-assignment index not created; the application guard and feedback:data-quality cover it.', [
                'sqlstate' => $exception->getCode(),
                'message' => $exception->getMessage(),
            ]);

            if (Schema::hasColumn('public_feedback_assignments', 'open_submission_id')) {
                DB::statement('ALTER TABLE public_feedback_assignments DROP COLUMN open_submission_id');
            }
        }
    }

    private function hasForeignKey(string $table, string $column): bool
    {
        return collect(Schema::getForeignKeys($table))->contains(fn (array $key): bool => $key['columns'] === [$column]);
    }

    private function hasIndex(string $table, string $name): bool
    {
        if (DB::pretending() || ! Schema::hasTable($table)) {
            return false;
        }

        return collect(Schema::getIndexes($table))->contains(fn (array $index): bool => strcasecmp((string) $index['name'], $name) === 0);
    }
};
