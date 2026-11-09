<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0032, step 3: each public submission type gets the table its module
 * owns — public_service_feedback (Service feedback), public_tip_offs
 * (Tip-offs), public_complaints (Public complaints) — with the same columns.
 *
 * 1. The three tables are created and every row is copied to its type's table,
 *    ids and references unchanged; the counts are checked before anything else.
 * 2. The case-handling tables (attachments, answers, assignments, history,
 *    subjects, referrals) keep their submission ids but lose the foreign key
 *    to the old table (ids are UUIDs, unique across the three tables); the
 *    model deletes them with their submission.
 * 3. The old table becomes `employee_service_feedback_legacy`, kept read-only
 *    for one release (step 4 drops it after checking every row was copied).
 * 4. `employee_service_feedback` becomes a read-only view over the three
 *    tables, so reads across types (reports, the Situation Room) keep one shape.
 *
 * migration-safety: reviewed. The rename keeps every row in the legacy table and the
 * drops only remove leftovers of a stopped run; the plan is ADR 0032, rehearsed on
 * MariaDB with a copy of the data (rows counted before the switch, rollback tested).
 *
 * MySQL DDL is not transactional, so every step can be run again: a run that
 * stopped half way starts over from whichever of the old table or the legacy
 * copy holds the rows. A later migration that adds a column to submissions
 * adds it to the three tables and recreates the view the way createView() does.
 */
return new class extends Migration
{
    private const ORIGINAL = 'employee_service_feedback';

    private const LEGACY = 'employee_service_feedback_legacy';

    /** type => [table, index prefix] */
    private const TABLES = [
        'feedback' => ['public_service_feedback', 'psf'],
        'suggestion' => ['public_tip_offs', 'pto'],
        'complaint' => ['public_complaints', 'pcs'],
    ];

    private const CHILDREN = [
        'employee_service_feedback_attachments' => 'employee_service_feedback_id',
        'public_feedback_answers' => 'submission_id',
        'public_feedback_assignments' => 'submission_id',
        'public_feedback_referrals' => 'submission_id',
        'public_feedback_status_history' => 'submission_id',
        'public_feedback_subject_employees' => 'submission_id',
    ];

    public function up(): void
    {
        if ($this->viewExists()) {
            return;
        }
        $source = Schema::hasTable(self::LEGACY) ? self::LEGACY : self::ORIGINAL;

        // Leftovers of a run that stopped before the view existed hold no new rows: the app writes there only through the view's model.
        foreach (self::TABLES as [$table]) {
            Schema::dropIfExists($table);
        }
        foreach (self::TABLES as $type => [$table, $prefix]) {
            Schema::create($table, fn (Blueprint $t) => $this->columns($t, $type, $prefix));
            $this->pgsqlChecks($table, $type, $prefix);
        }

        $columns = Schema::getColumnListing(self::TABLES['complaint'][0]);
        $list = implode(', ', array_map(fn (string $c) => DB::getQueryGrammar()->wrap($c), $columns));
        foreach (self::TABLES as $type => [$table]) {
            DB::statement("INSERT INTO {$table} ({$list}) SELECT {$list} FROM {$source} WHERE type = ?", [$type]);
            $copied = DB::table($table)->count();
            $expected = DB::table($source)->where('type', $type)->count();
            if ($copied !== $expected) {
                throw new RuntimeException("Public submissions split: {$table} has {$copied} rows, {$source} has {$expected} of type {$type}. Nothing was switched; run the migration again.");
            }
        }
        $unknown = DB::table($source)->whereNotIn('type', array_keys(self::TABLES))->count();
        if ($unknown > 0) {
            throw new RuntimeException("Public submissions split: {$unknown} rows of an unknown type in {$source}. Nothing was switched.");
        }

        foreach (self::CHILDREN as $child => $column) {
            $this->dropForeignKeyTo($child, $column);
        }
        // SQLite drops a foreign key by rebuilding the table, which loses the partial
        // "one open assignment per submission" index; put it back as it was.
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('DROP INDEX IF EXISTS pfa_one_open_per_submission');
            DB::statement('CREATE UNIQUE INDEX pfa_one_open_per_submission ON public_feedback_assignments (submission_id) WHERE ended_at IS NULL');
        }

        if ($source === self::ORIGINAL) {
            Schema::rename(self::ORIGINAL, self::LEGACY);
        }
        self::createView();
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::LEGACY)) {
            throw new RuntimeException('Public submissions split: the legacy table is gone (step 4 ran); roll back is not possible.');
        }
        DB::statement('DROP VIEW IF EXISTS '.self::ORIGINAL);
        // Rows written since the split go back to the one table.
        foreach (self::TABLES as [$table]) {
            $ids = DB::table($table)->pluck('id')->all();
            foreach (array_chunk($ids, 500) as $chunk) {
                DB::table(self::LEGACY)->whereIn('id', $chunk)->delete();
            }
            $columns = Schema::getColumnListing($table);
            $list = implode(', ', array_map(fn (string $c) => DB::getQueryGrammar()->wrap($c), $columns));
            DB::statement('INSERT INTO '.self::LEGACY." ({$list}) SELECT {$list} FROM {$table}");
        }
        Schema::rename(self::LEGACY, self::ORIGINAL);
        foreach (self::TABLES as [$table]) {
            Schema::dropIfExists($table);
        }
    }

    /** The read-only view over the three type tables; also used by later migrations that add columns. */
    public static function createView(): void
    {
        DB::statement('DROP VIEW IF EXISTS '.self::ORIGINAL);
        $columns = Schema::getColumnListing(self::TABLES['complaint'][0]);
        $list = implode(', ', array_map(fn (string $c) => DB::getQueryGrammar()->wrap($c), $columns));
        $selects = array_map(fn (array $t) => "SELECT {$list} FROM {$t[0]}", array_values(self::TABLES));
        DB::statement('CREATE VIEW '.self::ORIGINAL.' AS '.implode(' UNION ALL ', $selects));
    }

    /** In this database only: getViews() lists every schema on the server. */
    private function viewExists(): bool
    {
        return Schema::hasView(self::ORIGINAL);
    }

    private function dropForeignKeyTo(string $table, string $column): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        foreach (Schema::getForeignKeys($table) as $key) {
            if (in_array($key['foreign_table'], [self::ORIGINAL, self::LEGACY], true) && $key['columns'] === [$column]) {
                Schema::table($table, function (Blueprint $t) use ($key, $column): void {
                    // SQLite's foreign keys have no names; the column identifies them.
                    $key['name'] ? $t->dropForeign($key['name']) : $t->dropForeign([$column]);
                });
            }
        }
    }

    /** The submission columns, as the old table had them (2026-11-08). */
    private function columns(Blueprint $t, string $type, string $p): void
    {
        $t->uuid('id')->primary();
        $t->uuid('employee_id')->nullable();
        $t->uuid('employee_feedback_token_id')->nullable();
        $t->uuid('organization_id')->nullable();
        $t->uuid('organization_unit_id')->nullable();
        $t->string('organization_unit_name_snapshot')->nullable();
        $t->string('organization_unit_name_am_snapshot')->nullable();
        $t->string('organization_unit_type_snapshot', 100)->nullable();
        $t->string('unit_selection_mode', 20)->nullable();
        $t->uuid('position_id')->nullable();
        $t->uuid('position_service_id')->nullable();
        $t->string('service_no_snapshot', 40)->nullable();
        $t->string('service_name_snapshot')->nullable();
        $t->unsignedTinyInteger('rating')->nullable();
        $t->string('feedback_kind', 20)->nullable();
        $t->string('service_point', 120)->nullable();
        $t->text('comment')->nullable();
        $t->string('client_name')->nullable();
        $t->text('client_contact')->nullable();
        $t->string('ip_address', 45)->nullable();
        $t->text('user_agent')->nullable();
        $t->string('status', 16)->default('pending');
        $t->timestamp('reviewed_at')->nullable();
        $t->text('review_note')->nullable();
        $t->timestamps();
        $t->unsignedBigInteger('reviewed_by')->nullable();
        $t->string('type', 16)->default($type);
        $t->uuid('employee_assignment_id')->nullable();
        $t->string('reference_number', 80)->nullable()->unique("{$p}_reference_unique");
        $t->date('incident_date')->nullable();
        $t->string('incident_location')->nullable();
        $t->string('target_type', 16)->default('employee');
        $t->string('source_type', 32)->default('employee_card_qr');
        $t->uuid('organization_target_id')->nullable();
        $t->uuid('source_organization_qr_id')->nullable();
        $t->string('target_name_snapshot')->nullable();
        $t->string('target_code_snapshot', 60)->nullable();
        $t->string('subject', 200)->nullable();
        $t->string('submitter_identity_mode', 24)->default('optional');
        $t->timestamp('submitted_at')->nullable();
        $t->timestamp('acknowledged_at')->nullable();
        $t->timestamp('assigned_at')->nullable();
        $t->timestamp('resolved_at')->nullable();
        $t->timestamp('closed_at')->nullable();
        $t->string('tip_type', 32)->nullable();
        $t->string('intake_channel', 16)->nullable()->default('WEB_QR');
        $t->text('client_email')->nullable();
        $t->string('submitter_organization', 200)->nullable();
        $t->text('submitter_address')->nullable();
        $t->text('complaint_details')->nullable();
        $t->text('tip_details')->nullable();
        $t->text('involved_parties')->nullable();
        $t->uuid('question_set_id')->nullable();
        $t->uuid('service_id')->nullable();
        $t->string('service_text', 200)->nullable();
        $t->date('service_date')->nullable();
        $t->string('service_time_band', 16)->nullable();

        $t->index(['employee_id', 'created_at'], "{$p}_employee_created_idx");
        $t->index(['organization_id', 'created_at'], "{$p}_org_created_idx");
        $t->index('status', "{$p}_status_idx");
        $t->index(['organization_id', 'service_no_snapshot'], "{$p}_org_service_no_idx");
        $t->index(['employee_id', 'service_no_snapshot'], "{$p}_employee_service_no_idx");
        $t->index(['type', 'created_at'], "{$p}_type_created_idx");
        $t->index('position_service_id', "{$p}_position_service_idx");
        $t->index(['target_type', 'type', 'submitted_at'], "{$p}_target_type_submitted_idx");
        $t->index(['organization_id', 'status', 'submitted_at'], "{$p}_org_status_submitted_idx");
        $t->index(['organization_target_id', 'submitted_at'], "{$p}_org_target_submitted_idx");
        $t->index(['type', 'tip_type', 'submitted_at'], "{$p}_type_tip_submitted_idx");
        $t->index(['organization_id', 'type', 'submitted_at'], "{$p}_org_type_submitted_idx");
        $t->index('service_id', "{$p}_service_idx");
        $t->index('question_set_id', "{$p}_question_set_idx");
        $t->index('employee_feedback_token_id', "{$p}_token_idx");
        $t->index('employee_assignment_id', "{$p}_assignment_idx");
        $t->index('organization_unit_id', "{$p}_unit_idx");
        $t->index('position_id', "{$p}_position_idx");

        $t->foreign('employee_id', "{$p}_employee_fk")->references('id')->on('employees');
        $t->foreign('employee_feedback_token_id', "{$p}_token_fk")->references('id')->on('employee_feedback_tokens')->nullOnDelete();
        $t->foreign('organization_id', "{$p}_organization_fk")->references('id')->on('organizations')->nullOnDelete();
        $t->foreign('organization_target_id', "{$p}_organization_target_fk")->references('id')->on('organizations');
        $t->foreign('organization_unit_id', "{$p}_unit_fk")->references('id')->on('organization_units')->nullOnDelete();
        $t->foreign('position_id', "{$p}_position_fk")->references('id')->on('positions')->nullOnDelete();
        $t->foreign('position_service_id', "{$p}_position_service_fk")->references('id')->on('position_services')->nullOnDelete();
        $t->foreign('employee_assignment_id', "{$p}_assignment_fk")->references('id')->on('employee_assignments')->nullOnDelete();
        $t->foreign('question_set_id', "{$p}_question_set_fk")->references('id')->on('public_feedback_question_sets');
        $t->foreign('service_id', "{$p}_service_fk")->references('id')->on('services');
    }

    /** The PostgreSQL CHECK constraints the old table had, per table (the model checks them on every driver). */
    private function pgsqlChecks(string $table, string $type, string $p): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$p}_type_chk CHECK (type = '{$type}')");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$p}_target_integrity_chk CHECK ((target_type = 'employee' AND employee_id IS NOT NULL AND organization_target_id IS NULL) OR (target_type = 'organization' AND organization_target_id IS NOT NULL AND employee_id IS NULL))");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$p}_source_target_chk CHECK ((target_type = 'employee' AND source_type = 'employee_card_qr') OR (target_type = 'organization' AND source_type = 'organization_feedback_qr'))");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$p}_tip_type_chk CHECK (tip_type IS NULL OR (type = 'suggestion' AND tip_type IN ('CORRUPTION', 'IMPROPER_PRACTICE')))");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$p}_intake_channel_chk CHECK (intake_channel IS NULL OR intake_channel IN ('WEB_QR', 'PHONE', 'ORAL', 'WRITTEN'))");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$p}_service_time_band_chk CHECK (service_time_band IS NULL OR service_time_band IN ('morning', 'afternoon', 'evening'))");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$p}_org_service_context_chk CHECK (target_type = 'organization' OR (service_id IS NULL AND service_text IS NULL AND question_set_id IS NULL))");
    }
};
