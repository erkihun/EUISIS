<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One submissions table for both public feedback targets.
 *
 * `employee_service_feedback` keeps every existing row and column. It gains
 * the target (employee or organization), the source QR that produced the
 * submission, snapshots of the target's identity at submission time, and the
 * workflow timestamps. Existing rows are employee-targeted submissions from
 * the employee card QR by definition, which the column defaults record.
 *
 * On PostgreSQL two CHECK constraints make the rule structural: exactly one
 * primary target, and a source that matches it (brief §23, 24). SQLite is the
 * fast test driver only; the model enforces the same rule in PHP everywhere.
 */
return new class extends Migration
{
    private const TABLE = 'employee_service_feedback';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->string('target_type', 16)->default('employee');
            $table->string('source_type', 32)->default('employee_card_qr');
            $table->foreignUuid('organization_target_id')->nullable()
                ->constrained('organizations')->restrictOnDelete();
            $table->uuid('source_organization_qr_id')->nullable();
            $table->string('target_name_snapshot')->nullable();
            $table->string('target_code_snapshot', 60)->nullable();
            $table->uuid('category_id')->nullable()->index();
            $table->string('subject', 200)->nullable();
            $table->string('submitter_identity_mode', 24)->default('optional');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->index(['target_type', 'type', 'submitted_at'], 'esf_target_type_submitted_idx');
            $table->index(['organization_id', 'status', 'submitted_at'], 'esf_context_org_status_idx');
            $table->index(['organization_target_id', 'submitted_at'], 'esf_org_target_submitted_idx');
        });

        // Legacy rows were submitted when they were created.
        DB::table(self::TABLE)->whereNull('submitted_at')->update(['submitted_at' => DB::raw('created_at')]);

        // An organization-targeted submission has no employee.
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->uuid('employee_id')->nullable()->change();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE '.self::TABLE.' ADD CONSTRAINT esf_target_integrity_chk CHECK ('
                ."(target_type = 'employee' AND employee_id IS NOT NULL AND organization_target_id IS NULL) OR "
                ."(target_type = 'organization' AND organization_target_id IS NOT NULL AND employee_id IS NULL))"
            );
            DB::statement(
                'ALTER TABLE '.self::TABLE.' ADD CONSTRAINT esf_source_target_chk CHECK ('
                ."(target_type = 'employee' AND source_type = 'employee_card_qr') OR "
                ."(target_type = 'organization' AND source_type = 'organization_feedback_qr'))"
            );
        }
    }

    public function down(): void
    {
        if (DB::table(self::TABLE)->whereNotNull('organization_target_id')->exists()) {
            throw new RuntimeException(
                'Organization-targeted public submissions exist; export or migrate them before rolling back the unified target columns.'
            );
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS esf_target_integrity_chk');
            DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS esf_source_target_chk');
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropIndex('esf_target_type_submitted_idx');
            $table->dropIndex('esf_context_org_status_idx');
            $table->dropIndex('esf_org_target_submitted_idx');
            $table->dropForeign(['organization_target_id']);
            $table->dropIndex(['category_id']);
            $table->dropColumn([
                'target_type', 'source_type', 'organization_target_id', 'source_organization_qr_id',
                'target_name_snapshot', 'target_code_snapshot', 'category_id', 'subject',
                'submitter_identity_mode', 'submitted_at', 'acknowledged_at', 'assigned_at',
                'resolved_at', 'closed_at',
            ]);
        });

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->uuid('employee_id')->nullable(false)->change();
        });
    }
};
