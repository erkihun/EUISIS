<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * P3 (ADR 0010): tamper evidence for the audit trail, and break-glass access.
 *
 *  - core_audit_logs gains a sequence, a hash of its content and a chain
 *    hash (each row's hash folded into the one before), filled by the sealer
 *    (core:audit-seal) in sequence order; audit_seals signs each sealed range;
 *    audit_verifications keeps what core:audit-verify found.
 *  - The table becomes append-only in the database: a trigger refuses every
 *    DELETE, and every UPDATE except the sealer's one-time filling of the
 *    sequence and hashes of a row not yet sealed. (PostgreSQL and SQLite; on
 *    MySQL the trigger is created where the server allows it, and the
 *    verification still finds any change.)
 *  - break_glass_grants: time-limited, approved elevation (ADR 0010); audit
 *    rows written under one name it.
 */
return new class extends Migration
{
    private const CONTENT = ['id', 'actor_user_id', 'action', 'subject_type', 'subject_id', 'organization_id', 'old_values', 'new_values', 'reason',
        'request_id', 'request_ip', 'user_agent', 'source', 'api_client_id', 'break_glass_grant_id', 'created_at'];

    public function up(): void
    {
        Schema::create('break_glass_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->json('permissions');
            $table->string('scope_type', 16);
            $table->uuid('organization_id')->nullable();
            $table->text('reason');
            $table->unsignedInteger('duration_minutes');
            $table->string('status', 16)->default('requested');
            $table->dateTime('requested_at');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('ended_by')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->text('end_reason')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'expires_at'], 'break_glass_grants_user_idx');
            $table->index(['status'], 'break_glass_grants_status_idx');
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('decided_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('ended_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('audit_seals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('from_sequence');
            $table->unsignedBigInteger('to_sequence')->unique();
            $table->unsignedInteger('row_count');
            $table->char('head_hash', 64);
            $table->char('signature', 64);
            $table->uuid('previous_seal_id')->nullable();
            $table->dateTime('created_at');
        });

        Schema::create('audit_verifications', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->boolean('ok')->default(false);
            $table->unsignedBigInteger('checked_rows')->default(0);
            $table->unsignedBigInteger('first_bad_sequence')->nullable();
            $table->string('message', 1000)->nullable();
            $table->unsignedBigInteger('run_by')->nullable();
        });

        Schema::table('core_audit_logs', function (Blueprint $table): void {
            $table->uuid('break_glass_grant_id')->nullable();
            $table->unsignedBigInteger('sequence')->nullable()->unique();
            $table->char('record_hash', 64)->nullable();
            $table->char('chain_hash', 64)->nullable();
            $table->uuid('audit_seal_id')->nullable();
            $table->index(['break_glass_grant_id'], 'core_audit_logs_break_glass_idx');
        });

        $this->appendOnly();
    }

    public function down(): void
    {
        $this->dropAppendOnly();
        Schema::table('core_audit_logs', function (Blueprint $table): void {
            $table->dropIndex('core_audit_logs_break_glass_idx');
            $table->dropUnique(['sequence']);
            $table->dropColumn(['break_glass_grant_id', 'sequence', 'record_hash', 'chain_hash', 'audit_seal_id']);
        });
        Schema::dropIfExists('audit_verifications');
        Schema::dropIfExists('audit_seals');
        Schema::dropIfExists('break_glass_grants');
    }

    private function appendOnly(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            $changed = implode(' OR ', array_map(fn (string $c): string => "NEW.{$c}::text IS DISTINCT FROM OLD.{$c}::text", self::CONTENT));
            DB::unprepared(<<<SQL
                CREATE OR REPLACE FUNCTION core_audit_logs_append_only() RETURNS trigger AS \$\$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'core_audit_logs is append-only';
                    END IF;
                    IF OLD.sequence IS NOT NULL OR {$changed} THEN
                        RAISE EXCEPTION 'core_audit_logs is append-only: a row is sealed once and never changed';
                    END IF;
                    RETURN NEW;
                END
                \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER core_audit_logs_append_only BEFORE UPDATE OR DELETE ON core_audit_logs
                    FOR EACH ROW EXECUTE FUNCTION core_audit_logs_append_only();
                SQL);
        } elseif ($driver === 'sqlite') {
            $changed = implode(' OR ', array_map(fn (string $c): string => "NEW.{$c} IS NOT OLD.{$c}", self::CONTENT));
            DB::unprepared("CREATE TRIGGER core_audit_logs_no_delete BEFORE DELETE ON core_audit_logs BEGIN SELECT RAISE(ABORT, 'core_audit_logs is append-only'); END;");
            DB::unprepared("CREATE TRIGGER core_audit_logs_no_change BEFORE UPDATE ON core_audit_logs WHEN OLD.sequence IS NOT NULL OR {$changed} BEGIN SELECT RAISE(ABORT, 'core_audit_logs is append-only'); END;");
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            $changed = implode(' OR ', array_map(fn (string $c): string => "NOT (NEW.{$c} <=> OLD.{$c})", self::CONTENT));
            try {
                DB::unprepared("CREATE TRIGGER core_audit_logs_no_delete BEFORE DELETE ON core_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'core_audit_logs is append-only'");
                DB::unprepared("CREATE TRIGGER core_audit_logs_no_change BEFORE UPDATE ON core_audit_logs FOR EACH ROW BEGIN IF OLD.sequence IS NOT NULL OR {$changed} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'core_audit_logs is append-only'; END IF; END");
            } catch (Throwable $e) {
                Log::warning('The audit append-only trigger could not be created; core:audit-verify still detects changes.', ['error' => $e->getMessage()]);
            }
        }
    }

    private function dropAppendOnly(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS core_audit_logs_append_only ON core_audit_logs; DROP FUNCTION IF EXISTS core_audit_logs_append_only();');
        } else {
            DB::unprepared('DROP TRIGGER IF EXISTS core_audit_logs_no_delete');
            DB::unprepared('DROP TRIGGER IF EXISTS core_audit_logs_no_change');
        }
    }
};
